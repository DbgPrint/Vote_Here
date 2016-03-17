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
    
    require_once(__DIR__ . '/Thread.php');
    require_once(__DIR__ . '/DiplopiaVote.php');
    
    class DiplopiaThread extends Thread {
        // Presence of this substring in a comment indicates that it is a Diplopia vote thread.
        const VOTE_THREAD_MARKER = '[](#vote-diplopia-2)';
        
        private $subreddit, $postId, $commentId, $name, $admin, $created;
        
        // List of players (noveities), novelty operators, and their nicknames
        const PLAYERS_LIST_MARKER = 'players';
        const OPERATORS_LIST_MARKER = 'operators';
        private $players, $operators, $nicknames;
        
        // Lifetime of the vote thread, in hours since the comment was posted
        const LIFETIME = 48;
        
        // Instructions for voting
        const VOTE_INSTRUCTIONS = "Votes should be in the format **\*\*Vote:** *<novelty>* *<guess>***\*\*&nbsp;** or **\*\*Vote: no lynch\*\*&nbsp;**. If you don't want to make a guess regarding who is controlling the novelty, type **none** instead of *<guess>*.";
        
        // Creates a new vote thread from a comment. Throws an exception if the comment does not contain a vote thread.
        public function __construct(/* reddit comment */ $comment) {
            // Check if this is a vote thread
            if(strpos(strtolower($comment->data->body), self::VOTE_THREAD_MARKER) === false)
                throw new \Exception('Missing vote thread marker');
            
            // Gather basic information about the thread
            $this->subreddit = $comment->data->subreddit;
            $this->postId = str_replace('t3_', '', $comment->data->link_id);
            $this->commentId = $comment->data->id;
            $this->name = $comment->data->link_title;
            $this->admin = $comment->data->link_author;
            $this->created = $comment->data->created_utc;
            
            // List of operators and players will be retrieved from the day post by update().
            $this->players = $this->operators = null;
            
            // TODO: hardcoded nicknames, read them from the comment
            $this->nicknames = [
                'AbberantWhovian' => 'AberrantWhovian',
                'DiscordDraconeqqus' => 'DiscordDraconequus',
                'taco' => 'tortillatime',
            ];
        }
        
        // Returns the name of the thread.
        public function getName() {
            return '[Diplopia] ' . $this->name;
        }
        
        // Counts votes, triggers hammer, and posts updated vote thread. Returns a status string.
        // $client must be authorized to edit the vote thread comment.
        public function update(RdtAPI\Client $client) {
            // Do not update the thread if it is expired or the vote was hammered
            if(time() > ($this->created + self::LIFETIME * 3600))
                return 'Expired';
            
            // Fetch the vote thread comment, its replies, and the day post
            $info = $client->get('/r/' . $this->subreddit . '/comments/' . $this->postId . '/_/' .
                                 $this->commentId . '.json');
            $post = $info[0]->data->children[0];
            $comment = $info[1]->data->children[0];
            
            // Get the list of players and operators from the day post
            $players = self::getFromHiddenLink(self::PLAYERS_LIST_MARKER, $post->data->selftext);
            $operators = self::getFromHiddenLink(self::OPERATORS_LIST_MARKER, $post->data->selftext);
            $this->players = ($players === null) ? [] : explode('|', $players);
            $this->operators = ($operators === null) ? [] : explode('|', $operators);
                
            // Gather votes from comments
            $votes = [];
            if(isset($comment->data->replies->data)) {
                foreach($comment->data->replies->data->children as $reply) {
                    $vote = DiplopiaVote::makeFromComment($reply, $this->players, $this->operators, $this->nicknames);
                    if(!$vote)
                        continue;
                    $votes[] = $vote;
                }
            }
            
            // Filter votes
            $votes = (new LatestFilter())->apply($votes);
            $votes = (new SelfFilter())->apply($votes);
            $votes = (new DeadFilter($this))->apply($votes);
            
            // Merge votes into buckets
            $buckets = [];
            foreach($votes as $vote) {
                if(!isset($buckets[$vote->target]))
                    $buckets[$vote->target] = new Bucket($vote->target);
                $buckets[$vote->target]->addVote($vote);
            }
            
            // Weigh buckets
            (new TimeWeigher())->apply($buckets);
            
            // Make new text of the thread comment
            $newText = self::makeVoteThreadEmote($buckets) . ' ' .
                       self::VOTE_INSTRUCTIONS . "\r\n\r\n" .
                       self::makeVoteThreadTable($buckets) . "\r\n\r\n" .
                       self::VOTE_THREAD_MARKER;
            
            // Update the thread comment
            if(str_replace([ '&amp;', '&lt;', '&gt;' ], [ '&', '<', '>' ], $comment->data->body) === $newText)
                return 'Not changed';
            $client->post('/api/editusertext', [
                'api_type' => 'json',
                'text' => $newText,
                'thing_id' => 't1_' . $this->commentId
            ], true);
            return 'Updated';
        }
        
        // Returns an appropriate emote for the vote thread comment based on a list of buckets
        protected static function makeVoteThreadEmote(array /* of Bucket */ $buckets) {
            return str_replace(')', '-d)', parent::makeVoteThreadEmote($buckets));
        }
        
        // Returns the markup for the vote thread table. Buckets will be ordered by their weight in descending order
        // and all options with weight equal to the highest weight will be highlighed.
        protected static function makeVoteThreadTable(array /* of Bucket */ $buckets) {
            // Sort buckets by weight in descending order
            // NOTE: do not replace the comparison function with $b->getWeight() - $a->getWeight(): if the difference
            //       is between -1 and 1, usort() will truncate it to 0. See CAUTION in usort() documentation.
            usort($buckets, function($a, $b) { return $b->getWeight() > $a->getWeight() ? 1 : ($a->getWeight() === $b->getWeight() ? 0 : -1); });
            
            $markup = '';
            $maxWeight = null;
            foreach($buckets as $bucket) {
                $votes = $bucket->getVotes();
                usort($votes, function($a, $b) { return $a->time - $b->time; });
                
                $votesTotal = count($votes);
                $votesColumn = $votesTotal === 0 ? '&nbsp;' : $votesTotal . ' vote' . ($votesTotal !== 1 ? 's' : '');
                if(!$maxWeight) { // first row, has highest weight b/c array is sorted
                    $maxWeight = $bucket->getWeight();
                    $markup .= $bucket->getDisplayName() . '|' .
                               $votesColumn . "\r\n-|-:\r\n"; // header row, rendered bold automatically
                }
                else { // not first row
                    if($bucket->getWeight() === $maxWeight) // has highest weight nevertheless
                        $markup .= '**' . $bucket->getDisplayName() . '**|' . 
                                   '**' . $votesColumn . "**\r\n";
                    else
                        $markup .= $bucket->getDisplayName(true) . '|' . $votesColumn . "\r\n";
                }
                
                // Tally operator guesses
                $guesses = [];
                foreach($votes as $vote)
                    $guesses[] = $vote->guess;
                $guessesFreq = array_count_values($guesses);
                arsort($guessesFreq);
                
                // ...and add them into the table
                foreach($guessesFreq as $guess => $frequency) {
                    if($guess === 0)
                        continue;
                    $markup .= '&emsp;&emsp;&middot; controlled by *\/u/' . $guess . '*|' .
                               '' . $frequency . ' vote' . ($frequency !== 1 ? 's' : '') . "\r\n";
                }
            }
            return $markup;
        }
    }