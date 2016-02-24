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
    
    // Posts vote thread markers to Diplopia day threads to cause the vote threads to appear after the next update.
    final class DiplopiaPoster {
        // Username of the game mod in lowercase.
        const MODERATOR = 'diplopiamafia';
        
        private $client, $username, $subreddit;
        private $postsWithOurComments = [];
        
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
            
            // Consider posting a marker for each submission in the new queue
            echo "Posting markers for Diplopia...\r\n";
            foreach($posts->data->children as $post) {
                echo '  ', str_pad(substr($post->data->title, 0, 40), 40), ' - ';
                echo $this->postMarkerIfNeeded($post), "\r\n";
            }
        }
        
        // Posts a Diplopia vote thread marker if it is necessary and returns a status string.
        private function postMarkerIfNeeded(/* reddit link */ $post) {
            // This must be a Diplopia thread
            if(strtolower($post->data->author) !== self::MODERATOR)
                return 'Not Diplopia';
            
            // If we already placed a comment in the post, there is no need to consider placing another
            if(in_array('t3_' . $post->data->id, $this->postsWithOurComments, true))
                return 'Visited';
            
            // NOTE the space after 'day': we are expecting a number (in the word or digital form) there!
            if(strpos(strtolower($post->data->title), 'day ') === false || $post->data->selftext === '')
                return 'Not Day';
            
            // Post the marker
            $this->client->post('/api/comment', [
                'api_type' => 'json',
                'text' => '[](/sbstare)' . DiplopiaThread::VOTE_THREAD_MARKER,
                'thing_id' => $post->data->name
            ], true);
            return 'Posted';
        }
    }