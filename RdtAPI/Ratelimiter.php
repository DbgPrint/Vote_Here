<?php
    /**
     * RdtAPI
     * Copyright (C) 2014 DbgPrint <dbgprintex@gmail.com>
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
    
    namespace RdtAPI;
    
    final class Ratelimiter {
        private $minDelay = 1;
        
        private $nextActionTime = null;
        
        private $periodActionsLeft = null;
        private $periodResetTime = null;
        
        // Sets the allowed time for the next action. Should be called every time an action is performed.
        public function setNextAction() {
            $this->nextActionTime = time() + $this->minDelay;
            
            if($this->periodActionsLeft !== null && (--$this->periodActionsLeft) <= 0) {
                $this->nextActionTime = max($this->nextActionTime, $this->periodResetTime);
                $this->periodActionsLeft = $this->periodResetTime = null;
            }
        }
        
        // Sets the number of remaining actions in the current ratelimiting period.
        public function setPeriodActionsLeft($actionsLeft) {
            $this->periodActionsLeft = $actionsLeft;
        }
        
        // Sets the time when the current ratelimiting period ends.
        public function setPeriodResetTime($resetTime) {
            $this->periodResetTime = $resetTime;
        }
        
        // Sets the minimal delay between actions.
        public function setMinDelay($seconds) {
            $this->minDelay = $seconds;
        }
        
        // Returns true if the action should be blocked at this moment.
        public function isBlocked() {
            return $this->nextActionTime !== null && time() < $this->nextActionTime;
        }
        
        // Blocks script execution until the next action is allowed.
        public function waitForNextAction() {
            if($this->isBlocked())
                sleep($this->nextActionTime - time());
        }
    }