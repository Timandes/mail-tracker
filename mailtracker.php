#! /bin/env php
<?php
/**
 * Mail Tracker
 *
 * @package timandes\mail-tracker
 * @license Apache License Version 2.0
 * @author Timandes White <timands@gmail.com>
 */

ini_set('display_errors', true);
error_reporting(E_ALL);
ini_set('memory_limit', '2048M');

define('EXIT_SUCCESS', 0);
define('EXIT_FAILURE', 1);

$GLOBALS['gaSettings'] = array(
        'verbose' => 3,
        'callback_url' => 'http://localhost/rpc.php?m=%s',
        'spool_dir' => '/var/spool/mailtracker',
    );

require_once __DIR__ . '/bootstrap.php';

function mail_tracker_load_config_file()
{
    $configPath = '/etc/mail-tracker.conf';
    if (!file_exists($configPath))
        return;

    include $configPath;

    foreach ($configs as $k => $v)
        $GLOBALS['gaSettings'][$k] = $v;
}

function mail_tracker_dependency_check()
{
    if(!class_exists('Event')) {
        fprintf(STDERR, "Extension 'event' is missing.\n");
        return false;
    }

    return true;
}

function mail_tracker_send_message_delivery_status($messageId, $obj)
{
    $urlTemplate = $GLOBALS['gaSettings']['callback_url'];
    if (!$urlTemplate)
        throw new \RuntimeException("Empty 'callback_url' was found");

    $url = sprintf($urlTemplate, urlencode($messageId));

    $payload = json_decode(json_encode($obj), true);
    $options = array(
            'body' => http_build_query($payload),
        );

    $client = new GuzzleHttp\Client();
    $client->request('POST', $url, $options);
}

function mail_tracker_build_item_file_path($queueItemId)
{
    return $GLOBALS['gaSettings']['spool_dir']
         . '/' . substr($queueItemId, 0, 1)
         . '/' . substr($queueItemId, 1, 1)
         . '/' . substr($queueItemId, 2);
}

function mail_tracker_save_mapping_to_item_file($messageId, $queueItemId)
{
    $path = mail_tracker_build_item_file_path($queueItemId);
    if (!file_exists($path)) {
        $dirPath = dirname($path);
        if (!file_exists($dirPath))
            mkdir($dirPath, 0777, true);

        file_put_contents($path, $messageId);
        return;
    }

    fprintf(STDOUT, "Item file %s exists".PHP_EOL, $path);
    $messageIdInFile = mail_tracker_get_message_id_from_item_file($queueItemId);
    if ($messageIdInFile != $messageId)
        throw new \RuntimeException("Conflict item {$queueItemId} was found between {$messageIdInFile}(old) and {$messageId}(new)");
}

function mail_tracker_get_message_id_from_item_file($queueItemId)
{
    $path = mail_tracker_build_item_file_path($queueItemId);
    if (!file_exists($path))
        return null;

    $r = file_get_contents($path);
    if (!$r)
        return null;

    return trim($r);
}

function mail_tracker_handle_one_line($line)
{
    $parser = new \timandes\parser\MailLogParser();
    $obj = $parser->parse($line);
    if ($obj->processName != 'cleanup')
        return;

    if (!$obj->queueItemId)
        return;

    if (preg_match('/message-id=<([^>]+)>/', $obj->Headermessage, $matches)) {
        $messageId = $matches[1];

        fprintf(STDOUT, "Found message=%s, item=%s, saving mapping to item file ...".PHP_EOL, $messageId, $obj->queueItemId);
        mail_tracker_save_mapping_to_item_file($messageId, $obj->queueItemId);
    } elseif ($obj->status) {
        fprintf(STDOUT, "Found item=%s, sending to remote server ...".PHP_EOL, $obj->queueItemId);
        $messageId = mail_tracker_get_message_id_from_item_file($obj->queueItemId);
        if (!$messageId) {
            fprintf(STDERR, "Fail to find message ID from item file".PHP_EOL);
            return;
        }
        mail_tracker_send_message_delivery_status($messageId, $obj);
    }
}

function mail_tracker_event_handler_new_lines_arrived($ev)
{
    $buffer = $ev->getInput();

    while (null !== ($line = $buffer->readLine(EventBuffer::EOL_CRLF)))
        mail_tracker_handle_one_line($line);
}

function main($argc, $argv)
{
    if (!mail_tracker_dependency_check())
        return EXIT_FAILURE;

    mail_tracker_load_config_file();

    $eb = new EventBase();

    $ev = Event::signal($eb, SIGTERM, function() use($eb) {
        $eb->stop();
    });
    $ev->add();

    $ev = new EventBufferEvent($eb, STDIN, EventBufferEvent::OPT_DEFER_CALLBACKS
            , 'mail_tracker_event_handler_new_lines_arrived', null, function($ev, $events) use($eb) {
                if ($events & EventBufferEvent::EOF
                        || $events & EventBufferEvent::ERROR
                        || $events & EventBufferEvent::TIMEOUT)
                    $eb->stop();
            });
    $ev->enable(Event::READ);

    $eb->dispatch();

    return EXIT_SUCCESS;
}

exit(main($argc, $argv));
