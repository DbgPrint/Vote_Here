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
    
    // A Bucket represents a group of Votes for a particular target. Each bucket has a weight that indicates how close
    // it is to determining the outcome of the day.
    class Bucket {
        private $target;
        private $votes = [];
        private $weight = 0.0;
        
        // Creates a new bucket.
        public function __construct(/* string */ $target) {
            $this->target = $target;
        }
        
        // Returns the username of this bucket's target. Null represents no lynch.
        public function getTarget() {
            return $this->target;
        }
        
        // Adds a vote to the bucket. Returns false if the target of the vote does not match the target of the bucket.
        public function addVote(Vote $vote) {
            if($vote->target !== $this->target)
                return false;
            
            $this->votes[] = $vote;
            return true;
        }
        
        // Returns an array of votes.
        public function getVotes() {
            return $this->votes;
        }
        
        // Sets the weight of this bucket.
        public function setWeight(/* double */ $weight) {
            $this->weight = $weight;
        }
        
        // Returns the weight of this bucket.
        public function getWeight() {
            return $this->weight;
        }
        
        // Returns the bucket's name that will be displayed to players.
        public function getDisplayName(/* bool */ $escapeUsername = false) {
            if($this->target === null)
                return 'No Lynch';
            $escapedTarget = str_replace('_', '\_', $this->target);
            return ($escapeUsername ? '\\' : '') . '/u/' . $escapedTarget;
        }
    }