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
	
    require_once(__DIR__ . '/TextUtils.php');
    
    require_once(__DIR__ . '/Vote.php');
    require_once(__DIR__ . '/Bucket.php');    
	require_once(__DIR__ . '/Filters.php');
    require_once(__DIR__ . '/Weighers.php');
    
    class Thread {
        // Presence of this substring in a comment indicates that it is supposed to be a vote thread.
        const VOTE_THREAD_MARKER = '[](#vote)';
        
        private $subreddit, $postId, $commentId, $name, $admin;
        
        // List of players and their nicknames
        const PLAYERS_LIST_MARKER = 'players';
        private $players, $nicknames;
        
        // List of vote filters
        const FILTERS_LIST_MARKER = 'filters';
        const DEFAULT_FILTERS = 'latest|dead';
        private $filters;
        
        // The weigher used to rank buckets of votes
        const WEIGHER_MARKER = 'count';
        const DEFAULT_WEIGHER = 'basic';
        private $weigher, $weigherName;
        
        // Lifetime of the vote thread, in hours since the comment was posted
        const LIFETIME_MARKER = 'lifetime';
        const DEFAULT_LIFETIME = 72;
        private $created, $lifetime;
        
        // Hammer state
        const HAMMER_MARKER = 'hammer';
        const HAMMER_INACTIVE = 0;
        const HAMMER_READY = 1;
        const HAMMER_TRIGGERED = 2;
        private $hammer;
        
        // Instructions for voting
        const VOTE_INSTRUCTIONS = "Votes should be in the format **\*\*Vote:** *username* or *no lynch* **\*\*&nbsp;**. \r\n\r\n";
        
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
            
            // Get a list of players. If the comment doesn't have any, it'll be set to null and filled in by update().
            $this->players = self::getFromHiddenLink(self::PLAYERS_LIST_MARKER, $comment->data->body);
            if($this->players !== null)
                $this->players = explode('|', $this->players);
            
            // TODO: hardcoded nicknames, read them from the comment
            $this->nicknames = [ 'DiscordDraconeqqus' => 'DiscordDraconequus', 'taco' => 'tortillatime',
                                 'AbberantWhovian' => 'AberrantWhovian' ];
            
            // Create filters
            $this->filters = [];
            $filters = self::getFromHiddenLink(self::FILTERS_LIST_MARKER, $comment->data->body);
            foreach(explode('|', ($filters !== null ? $filters : self::DEFAULT_FILTERS)) as $filter) {
                if($filter === 'latest')
                    $this->filters['latest'] = new LatestFilter();
                elseif($filter === 'self')
                    $this->filters['self'] = new SelfFilter();
                elseif($filter === 'dead')
                    $this->filters['dead'] = new DeadFilter($this);
            }
            
            // Create a bucket weigher
            $this->weigher = null;
            foreach([self::getFromHiddenLink(self::WEIGHER_MARKER, $comment->data->body), self::DEFAULT_WEIGHER] as $name) {
                if($name === 'basic')
                    $this->weigher = new BasicWeigher();
                elseif($name === 'time')
                    $this->weigher = new TimeWeigher();
                elseif($name === 'rmajor')
                    $this->weigher = new RelativeMajorityRequiredWeigher();
                
                if($this->weigher !== null) {
                    $this->weigherName = $name;
                    break;
                }
            }
            
            // Read in the lifetime of the thread
            $this->created = $comment->data->created_utc;
            $this->lifetime = ceil((time() - $this->created) / 3600) + self::DEFAULT_LIFETIME;
            $lifetime = self::getFromHiddenLink(self::LIFETIME_MARKER, $comment->data->body);
            if($lifetime !== null && (int)$lifetime >= 0)
                $this->lifetime = (int)$lifetime;
            
            // Define the state of the hammer
            $hammerState = self::getFromHiddenLink(self::HAMMER_MARKER, $comment->data->body);
            $this->hammer = ($hammerState === null) ? self::HAMMER_INACTIVE : (int)$hammerState;
        }
        
        // Counts votes, triggers hammer, and posts updated vote thread. Returns a status string.
        // $client must be authorized to edit the vote thread comment.
        public function update(RdtAPI\Client $client) {
            // Do not update the thread if it is expired or the vote was hammered
            if(time() > ($this->created + $this->lifetime * 3600))
                return 'Expired';
            if($this->hammer === self::HAMMER_TRIGGERED)
                return 'Hammered';
            
            // Fetch the vote thread comment, its replies, and the day post
            $info = $client->get('/r/' . $this->subreddit . '/comments/' . $this->postId . '/_/' .
                                 $this->commentId . '.json');
            $post = $info[0]->data->children[0];
            $comment = $info[1]->data->children[0];
            
            // Use list of players mentioned in the post if we don't know who the players are
            if($this->players === null)
                $this->players = self::getMentionedUsernames($post->data->selftext);
            
            // Gather votes from comments
            $votes = [];
            if(isset($comment->data->replies->data)) {
                foreach($comment->data->replies->data->children as $reply) {
                    if(!($vote = Vote::makeFromComment($reply, $this->players, $this->nicknames)))
                        continue;
                    $votes[] = $vote;
                }
            }
            
            // Filter votes
            foreach($this->filters as $filter)
                $votes = $filter->apply($votes);
            
            // Merge votes into buckets
            $buckets = [];
            foreach($votes as $vote) {
                if(!isset($buckets[$vote->target]))
                    $buckets[$vote->target] = new Bucket($vote->target);
                $buckets[$vote->target]->addVote($vote);
            }
            
            // Weigh buckets
            $this->weigher->apply($buckets);
            
            // Check if the vote was hammered
            if($this->hammer === self::HAMMER_READY) {
                foreach($buckets as $bucket) {
                    if(count($bucket->getVotes()) >= ceil(count($this->players) / 2)) {
                        $this->hammer = self::HAMMER_TRIGGERED;
                        break;
                    }
                }
            }
            
            // Make new text of the thread comment
            $newText = self::makeVoteThreadEmote($buckets) . ' ' .
                       self::VOTE_INSTRUCTIONS .
                       self::makeVoteThreadTable($buckets, $this->hammer === self::HAMMER_TRIGGERED) . "\r\n\r\n" .
                       self::VOTE_THREAD_MARKER .
                       self::storeInHiddenLink(self::PLAYERS_LIST_MARKER, implode('|', $this->players)) .
                       self::storeInHiddenLink(self::FILTERS_LIST_MARKER, implode('|', array_keys($this->filters))) .
                       self::storeInHiddenLink(self::WEIGHER_MARKER, $this->weigherName) .
                       self::storeInHiddenLink(self::LIFETIME_MARKER, $this->lifetime) .
                       self::storeInHiddenLink(self::HAMMER_MARKER, $this->hammer);
            
            // Update the thread comment
            if(str_replace('&amp;', '&', $comment->data->body) === $newText)
                return 'Not changed';
            $client->post('/api/editusertext', [
                'api_type' => 'json',
                'text' => $newText,
                'thing_id' => 't1_' . $this->commentId
            ], true);
            return 'Updated';
        }
        
        // Returns the name of this vote thread
        public function getName() {
            return $this->name;
        }
        
        // Returns the list of players eligible for participation in this vote thread
        public function getPlayers() {
            return $this->players;
        }
        
        // Returns an appropriate emote for the vote thread comment based on a list of buckets
        private static function makeVoteThreadEmote(array /* of Bucket */ $buckets) {
            $votesTotal = array_reduce($buckets, function($carry, $b) { return $carry + count($b->getVotes()); }, 0);
            $stares = [ '[](/fillysbstare)' => 7, '[](/sbstare)' => 3, '[](/sb10)' => 2, '[](/sbigstare)' => 0 ];
                      // '[](/sbtaffy)[](/sp)' is too big
            // NOTE: $stares must sorted in reverse order!
            foreach($stares as $emote => $minVotes) {
                if($votesTotal >= $minVotes)
                    return $emote;
            }
            return '[](/sbdirty)'; // not supposed to happen
        }
        
        // Returns the markup for the vote thread table. Buckets will be ordered by their weight in descending order
        // and all options with weight equal to the highest weight will be highlighed. In addition, if $hammered is set
        // to true, these options will have a picture of hammer next to their display names.
        private static function makeVoteThreadTable(array /* of Bucket */ $buckets, /* bool */ $hammered = false) {
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
                    $markup .= $bucket->getDisplayName() . ($hammered ? ' &#x1f528;' : '') . '|' .
                               $votesColumn . "\r\n-|-:\r\n"; // header row, rendered bold automatically
                }
                else { // not first row
                    if($bucket->getWeight() === $maxWeight) // has highest weight nevertheless
                        $markup .= '**' . $bucket->getDisplayName() .  ($hammered ? ' &#x1f528;' : '') . '**|' . 
                                   '**' . $votesColumn . "**\r\n";
                    else
                        $markup .= $bucket->getDisplayName(true) . '|' . $votesColumn . "\r\n";
                }
                
                foreach($votes as $vote) {
                    $markup .= '&emsp;&emsp;&middot; *\/u/' . $vote->author . '*|' .
                               '[^(*' . date('M j, H:i', $vote->time) . '*)](' . $vote->url . ")\r\n";
                }
            }
            return $markup;
        }
        
        // Returns all usernames mentioned in a text, without duplicates.
        private static function getMentionedUsernames(/* string */ $text) {
            $matches = [];
            preg_match_all('/\/u\/(?P<username>[a-z0-9\-_]+)/i', $text, $matches);
            
            $usernames = [];
            foreach(array_unique(array_map('strtolower', $matches['username'])) as $username)
                $usernames[] = TextUtils::findStringCaseInsensitive($matches['username'], $username);
            
            return $usernames;
        }

        // Stores a string inside a hidden link with a given marker. NOTE: marker may not contain the pipe character.
        private static function storeInHiddenLink(/* string */ $marker, /* string */ $string) {
            return '[](#' . $marker . '|' . str_replace([ '\\', ')', "\r", "\n", "\t" ],
                                                        [ '\\\\', '\\)', '\\r', '\\n', '\\t' ], $string) . ')';
        }
        
        // Returns data stored in a hidden link with given marker.
        private static function getFromHiddenLink(/* string */ $marker, /* string */ $text) {
            $beginning = '[](#' . $marker . '|';
            if(($start = strpos($text, $beginning)) === false)
                return null;
            $start += strlen($beginning);
            
            $end = $start;
            while(true) {
                if(($end = strpos($text, ')', $end + 1)) === false)
                    return null;
                if($end >= 1 && $text[$end - 1] !== '\\')
                    break;
            }
            
            $data = substr($text, $start, $end - $start);
            $data = str_replace([ '\\\\', '\\)', '\\r', '\\n', '\\t' ], [ '\\', ')', "\r", "\n", "\t" ], $data);
            return $data;
        }
    }