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
    
    require_once(__DIR__ . '/Vote.php');
    
    // Represents a vote, which includes the name of the novelty to be lynched and the username of the player the voter
    // thinks is controlling the novelty.
    class DiplopiaVote extends Vote {
        const GUESS_NONE = 0; // not null because array_count_values() can only count integers and strings
        public $guess;
        
        // Creates a new vote.
        public function __construct(/* string */ $author, /* string */ $target, /* string or self::GUESS_* */ $guess,
                                    /* timestamp */ $time, /* string */ $url) {
            $this->author = $author;
            $this->target = $target;
            $this->guess = $guess;
            $this->time = $time;
            $this->url = $url;
        }
        
        // Creates a Vote from a vote comment. If a comment contains no correctly-formatted vote, returns null.
        public static function makeFromComment(/* reddit comment */ $comment, array /* of string */ $players,
                                               array /* of string */ $operators,
                                               array /* of string */ $nicknames = []) {
            // Remove text that should be ignored by the bot. Separate segments with space characters.
            $text = trim($comment->data->body);
            $text = self::removeTextEnclosedBy('~~', str_replace([ '~`', '`~' ], [ '~~', '~~' ], $text));
            $text = self::removeTextNotEnclosedBy('**', $text, ' ') . ' ' .
                    self::removeTextNotEnclosedBy('__', $text, ' ');
            
            // Determine the target of the vote.
            if(preg_match('/vote([\s:]+)no([\s]+)lynch/i', $text))
                $target = $guess = null;
            else {
                // Find all correctly-formatted votes.
                $matches = [];
                preg_match_all('/vote(([\s:]+)(\/u\/)?|\/u\/)(?P<target>[a-z0-9\-_]+)' .
                                    '(([\s:]+)(\/u\/)?|\/u\/)(?P<guess>[a-z0-9\-_]+)/i', $text, $matches);
                
                // Pick the first pair that unambiguously resolves to specific usernames
                $target = $guess = null;
                foreach($matches['target'] as $i => $targetMatch) {
                    $guessMatch = $matches['guess'][$i];

                    $targetUsername = self::resolveUsername($targetMatch, $players, $nicknames);
                    if($targetUsername)
                        $target = $targetUsername;
                    else
                        continue;
                    
                    if(strtolower($guessMatch) === 'none')
                        $guess = self::GUESS_NONE;
                    else {
                        $guessUsername = self::resolveUsername($guessMatch, $operators, $nicknames);
                        if($guessUsername)
                            $guess = $guessUsername;
                        else
                            continue;
                    }
                    
                    break;
                }
                
                // If there are no votes that can be resolved...
                if(!$target) {
                    // Try choosing the first correctly-formatted vote. If it's an invalid vote, it'll be filtered out
                    // later on.
                    if(count($matches['target']) > 0) {
                        $target = $matches['target'][0];
                        $guess = $matches['guess'][0];
                    }
                    
                    // If there are no votes in the comment at all, we have no choice, but to fail out.
                    else
                        return null;
                }
            }
            
            // Create the vote.
            $author = self::resolveUsername($comment->data->author, $players);
            $time = $comment->data->edited ? $comment->data->edited : $comment->data->created_utc;
            $url = 'https://www.reddit.com/r/' . $comment->data->subreddit . '/comments/' .
                    str_replace('t3_', '', $comment->data->link_id) . '/_/' . $comment->data->id . '?context=3';
            return new DiplopiaVote($author, $target, $guess, $time, $url);
        }
    }