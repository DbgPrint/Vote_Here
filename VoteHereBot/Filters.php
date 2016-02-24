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
    
    // Filters remove votes that are not valid according to the rules of a game. A new instance of a filter is created
    // for each Thread.
    abstract class Filter {
        // Returns an array of votes that passed through the filter.
        abstract public function apply(array /* of Vote */ $votes);
    }
    
    // Only picks the most recent vote by each voter, discarding all the previous ones. This approach ensures that each
    // player has only one vote.
    class LatestFilter extends Filter {
        public function apply(array /* of Vote */ $votes) {
            $votesByAuthor = []; // username => vote
            foreach($votes as $vote) {
                if(!isset($votesByAuthor[$vote->author]) || $votesByAuthor[$vote->author]->time < $time)
                    $votesByAuthor[$vote->author] = $vote;
            }
            return array_values($votesByAuthor);
        }
    }
    
    // Removes votes in which a person votes for him-/herself.
    class SelfFilter extends Filter {
        public function apply(array /* of Vote */ $votes) {
            $filtered = [];
            foreach($votes as $vote) {
                if($vote->target === null ||
                   strtolower($vote->target) !== strtolower($vote->author))
                    $filtered[] = $vote;
            }
            return $filtered;
        }
    }
    
    // Discards votes made for or by people who are not marked as living players in a Thread. If it has no players
    // listed, the filter will not remove any votes.
    class DeadFilter extends Filter {
        private $thread;
        
        public function __construct(Thread $thread) {
            $this->thread = $thread;
        }
        
        public function apply(array /* of Vote */ $votes) {
            $players = $this->thread->getPlayers();
            if(count($players) === 0)
                return $votes;
            
            $filtered = [];
            foreach($votes as $vote) {
                if(!in_array($vote->author, $players, true))
                    continue;
                
                if($vote->target !== null && !in_array($vote->target, $players, true))
                    continue;
                
                $filtered[] = $vote;
            }
            return $filtered;
        }
    }