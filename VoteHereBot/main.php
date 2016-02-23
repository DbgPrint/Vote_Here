<?php
    /**
     * Vote_Here Bot
     * Copyright (C) 2014-2016 DbgPrint <dbgprintex@gmail.com>
     * 
     * This program is free software: you can redistribute it and/or modify
     * it under the terms of the GNU General Public License as published by
     * the Free Software Foundation, either version 3 of the License, or
     * (at your option) any later version.
     * 
     * This program is distributed in the hope that it will be useful,
     * but WITHOUT ANY WARRANTY; without even the implied warranty of
     * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
     * GNU General Public License for more details.
     * 
     * You should have received a copy of the GNU General Public License
     * along with this program.  If not, see <http://www.gnu.org/licenses/>.
     */
    
    set_time_limit(30);
    date_default_timezone_set('America/New_York');
    
    require_once(__DIR__ . '/../RdtAPI/Client.php');
    
    require_once(__DIR__ . '/ReminderPoster.php');
    require_once(__DIR__ . '/Thread.php');
    
    require_once(__DIR__ . '/config.php');
    
    const EXIT_PAUSE = 5;
    
    echo USERAGENT, "\r\n", str_repeat('=', strlen(USERAGENT)), "\r\n";
    
    $client = new RdtAPI\Client();
    $client->setUseragent(USERAGENT);
    $client->authorize(CLIENT_ID, CLIENT_SECRET, USERNAME, PASSWORD);
    
    echo "Looking for vote threads...\r\n";
    $threads = [];
    $comments = $client->get('/user/' . USERNAME . '/comments.json');
    foreach($comments->data->children as $comment) {
        try {
            $threads[] = new Thread($comment);
        }
        catch(Exception $e) {
            continue;
        }
    }
    
    // TODO: edit threads based on commands from PMs
    
    echo "Updating vote threads...\r\n";
    foreach($threads as $thread) {
        echo '  ', str_pad(substr($thread->getName(), 0, 40), 40), ' - ';
        echo $thread->update($client), "\r\n";
    }
    echo "\r\n";
    
    $rp = new ReminderPoster($client, USERNAME, SUBREDDIT);
    $rp->run();
    
    echo 'Done';
    for($i = 0; $i < EXIT_PAUSE; ++$i) {
        sleep(1);
        echo '.';
    }