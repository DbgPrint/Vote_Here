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
    
    // Represents a vote for a particular person.
    class Vote {
        public $author, $target, $time, $url;
        
        // Creates a new vote.
        public function __construct(/* string */ $author, /* string */ $target, /* timestamp */ $time,
                                    /* string */ $url) {
            $this->author = $author;
            $this->target = $target;
            $this->time = $time;
            $this->url = $url;
        }
        
        // Creates a Vote from a vote comment. If a comment contains no correctly-formatted vote, returns null.
        public static function makeFromComment(/* reddit comment */ $comment, array /* of string */ $players,
                                               array /* of string */ $nicknames = []) {
            // Remove text that should be ignored by the bot. Separate segments with space characters.
            $text = trim($comment->data->body);
            $text = self::removeTextEnclosedBy('~~', str_replace([ '~`', '`~' ], [ '~~', '~~' ], $text));
            $text = self::removeTextNotEnclosedBy('**', $text, ' ') . ' ' .
                    self::removeTextNotEnclosedBy('__', $text, ' ');
            
            // Determine the target of the vote.
            if(preg_match('/vote([\s:]+)no([\s]+)lynch/i', $text))
                $target = null;
            else {
                // Find all correctly-formatted votes.
                $matches = [];
                preg_match_all('/vote(([\s:]+)(\/u\/)?|\/u\/)(?P<username>[a-z0-9\-_]+)/i', $text, $matches);
                
                // Pick the first that unambiguously resolves to a player.
                $target = null;
                foreach($matches['username'] as $match) {
                    if($username = self::resolveUsername($match, $players, $nicknames)) {
                        $target = $username;
                        break;
                    }
                }
                
                // If there are no votes that can be resolved...
                if(!$target) {
                    // Try choosing the first correctly-formatted vote. If it's an invalid vote, it'll be filtered out
                    // later on.
                    if(count($matches['username']) > 0)
                        $target = $matches['username'][0];
                    
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
            return new Vote($author, $target, $time, $url);
        }
        
        // Determines the exact username of a person mentioned in a comment using a list of possible usernames and their
        // nicknames. If only a portion of a username or nickname was mentioned, the function checks every possible
        // match and makes sure that all of them eventually resolve to the same person.
        protected static function resolveUsername(/* string */ $username, array /* of string */ $usernames,
                                                array /* of string */ $nicknames = []) {
            // First of all, try an exact match.
            $match = TextUtils::findStringCaseInsensitive($usernames, $username);
            if($match !== null)
                return $match;
            
            // Then, try to resolve the username as a nickname.
            $match = TextUtils::findStringCaseInsensitive(array_keys($nicknames), $username);
            if($match !== null) {
                $username = $nicknames[$match];
                unset($nicknames[$match]); // we don't want to come back to this nickname again
                return self::resolveUsername($username, $usernames, $nicknames);
            }
            
            // As a last resort, try using all usernames and nicknames that contain a portion of the username we're
            // trying to resolve. If there is only one that the username resolves to, choose it.
            $finalMatch = null;
            $matches = self::findStringsWith(array_merge($usernames, array_keys($nicknames)), $username);
            foreach($matches as $match) {
                $match = self::resolveUsername($match, $usernames, $nicknames);
                if($finalMatch === null)
                    $finalMatch = $match;
                elseif($match !== $finalMatch)
                    return null; // ambiguous
            }
            return $finalMatch;
        }
        
        // Finds strings in $haystack that contain $needle.
        protected static function findStringsWith($haystack, $needle) {
            $needleLowercase = strtolower($needle);
            $matches = [];
            foreach($haystack as $hay) {
                if(strpos(strtolower($hay), $needleLowercase) !== false)
                    $matches[] = $hay;
            }
            return $matches;
        }
        
        // Removes all groups of symbols _enclosed_ by $boundary. If a substring is not surrounded by $boundary on both
        // sides, it, along with the preceding instance of $boundary, will not be removed.
        protected static function removeTextEnclosedBy($boundary, $text) {
            $blocks = explode($boundary, $text);
            $text = '';
            for($i = 0, $total = count($blocks); $i < $total; ++$i) {
                if($i % 2 === 0)
                    $text .= $blocks[$i];
                else if(!isset($blocks[$i + 1]))
                    $text .= $boundary . $blocks[$i]; // there was no corresponding $boundary after the block
            }
            return $text;
        }
        
        // Removes all groups of symbols not enclosed by $boundary. A substring must be surrounded by $boundary on both
        // sides to remain in the output. Substrings in the ouptut will be separated by $separator. All instances of
        // $boundary will be removed.
        protected static function removeTextNotEnclosedBy($boundary, $text, $separator = "\n") {
            $blocks = explode($boundary, $text);
            $output = [];
            for($i = 0, $total = count($blocks); $i < $total; ++$i) {
                if($i % 2 === 1 && isset($blocks[$i + 1]))
                    $output[] = $blocks[$i];
            }
            return implode($separator, $output);
        }
    }