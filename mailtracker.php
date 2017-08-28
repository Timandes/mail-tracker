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
        'callback_url' => 'http://localhost/rpc.php?m=%s&q=%s',
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

function mail_tracker_handle_one_message($messageId, $queueItemId)
{
    fprintf(STDOUT, "Found message=%s, item=%s, sending to remote server ...".PHP_EOL, $messageId, $queueItemId);

    $urlTemplate = $GLOBALS['gaSettings']['callback_url'];
    if (!$urlTemplate)
        throw new \RuntimeException("Empty 'callback_url' was found");

    $url = sprintf($urlTemplate, urlencode($messageId), urlencode($queueItemId));

    $client = new GuzzleHttp\Client();
    $client->request('POST', $url);
}

function mail_tracker_handle_one_line($line)
{
    $parser = new \timandes\parser\MailLogParser();
    $obj = $parser->parse($line);
    if ($obj->processName != 'cleanup')
        return;

    if (!$obj->queueItemId)
        return;

    if (!preg_match('/message-id=<([^>]+)>/', $obj->Headermessage, $matches))
        return;

    $messageId = $matches[1];

    mail_tracker_handle_one_message($messageId, $obj->queueItemId);
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
