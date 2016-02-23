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
	
    // Posts vote thread reminders where they are needed.
	final class ReminderPoster {
        // Presence of this marker in the body of a post indicates that the mod does not want a vote thread
        const NO_THREAD_REQUIRED_MARKER = '[](#novote)';
        
        private $client, $username, $subreddit;
        private $postsWithOurComments = [];
        
        // The bot will only consider posts that were created between 'min' and 'max' seconds ago.
        private $postMinAge = 120; // 2 minutes
        private $postMaxAge = 1200; // 20 minutes
        
        // This text will be placed in a vote thread comment reminder.
        const REMINDER_TEXT = '[](/sbstare)';
        
        // Creates a new instance of the bot. The client must be authorized before the bot runs.
        public function __construct(RdtAPI\Client $client, /* string */ $username, /* string */ $subreddit) {
            $this->client = $client;
            $this->username = $username;
            $this->subreddit = $subreddit;
        }
        
        // Runs the bot.
        public function run() {
            echo "Retrieving the new queue and our own comments...\r\n";
            
            // The new queue
            $posts = $this->client->get('/r/' . $this->subreddit . '/new.json');
            
            // The list of our comments
            $comments = $this->client->get('/user/' . $this->username . '/comments.json');
            foreach($comments->data->children as $comment)
                $this->postsWithOurComments[] = $comment->data->link_id;
            
            // Consider posting a reminder for each submission in the new queue
            echo "Posting reminders...\r\n";
            foreach($posts->data->children as $post) {
                echo '  ', str_pad(substr($post->data->title, 0, 40), 40), ' - ';
                echo $this->publishReminderIfNeeded($post), "\r\n";
            }
        }
        
        // Publishes a reminder if a post needs it and returns a status string.
        private function publishReminderIfNeeded(/* reddit link */ $post) {
            // If we already placed a comment in the post, there is no need to consider placing another
            if(in_array('t3_' . $post->data->id, $this->postsWithOurComments, true))
                return 'Visited';
            
            // There is no need to worry about old posts, and we want to give the mod time to post his own vote thread
            if(time() > $post->data->created_utc + $this->postMaxAge)
                return 'Too old';
            if(time() < $post->data->created_utc + $this->postMinAge)
                return 'Too new';
            
            // NOTE the space after 'day': we are expecting a number (in the word or digital form) there!
            if(strpos(strtolower($post->data->title), 'day ') === false || $post->data->selftext === '')
                return 'Not a day thread';
            
            // Check if the mod explicitly stated that he doesn't want a vote thread
            if(strpos(strtolower($post->data->selftext), self::NO_THREAD_REQUIRED_MARKER) !== false)
                return 'Not required';
            
            // Make sure that there are no comments that are probably vote threads
            $info = $this->client->get('/r/' . $post->data->subreddit . '/comments/' . $post->data->id . '/_/.json');
            foreach($info[1]->data->children as $comment) {
                if($comment->data->author === $post->data->author)
                    return 'Has vote thread';
                
                // This was already checked in the beginning, but just in case. TODO: make this an assertion instead.
                if($comment->data->author === $this->username)
                    return 'Visited';
            }
            
            // Post the reminder
            $this->client->post('/api/comment', [
                'api_type' => 'json',
                'text' => self::REMINDER_TEXT,
                'thing_id' => $post->data->name
            ], true);
            return 'Posted';
        }
    }