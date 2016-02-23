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
	
    // Weighers assign weights to each Bucket of votes.
	abstract class Weigher {
        // Assigns weight to each bucket in a set
		abstract public function apply(array /* of Bucket */ &$buckets);
	}
    
    // Weighs every bucket solely based on the number of votes it has.
    class BasicWeigher extends Weigher {
        public function apply(array /* of Bucket */ &$buckets) {
            // Weigh each bucket based on its number of votes.
            foreach($buckets as $bucket)
                $bucket->setWeight(count($bucket->getVotes()));
        }
    }
    
    // Weighs every bucket by the number of votes it has and how early did it get all its votes. If there is a tie, the
    // bucket that got its votes first gets greater weight.
    class TimeWeigher extends Weigher {
        public function apply(array /* of Bucket */ &$buckets) {
            // Determine the last update time of each bucket.
            $lastUpdated = []; // bucket index => timestamp of the last vote
            foreach($buckets as $i => $bucket) {
                $lastUpdated[$i] = -INF;
                foreach($bucket->getVotes() as $vote) {
                    if($vote->time > $lastUpdated[$i])
                        $lastUpdated[$i] = $vote->time;
                }
            }
            
            // Find the range of update times.
            $minUpdateTime = +INF;
            $maxUpdateTime = -INF;
            foreach($lastUpdated as $time) {
                if($time < $minUpdateTime)
                    $minUpdateTime = $time;
                if($time > $maxUpdateTime)
                    $maxUpdateTime = $time;
            }
            
            // Assign weight to each bucket.
            foreach($lastUpdated as $i => $time) {
                // Scale the update time to the range [0, 1]. If there is only one update time, scale it to the center.
                if($maxUpdateTime !== $minUpdateTime)
                    $lastUpdatedNormalized = ($time - $minUpdateTime) / ($maxUpdateTime - $minUpdateTime);
                else
                    $lastUpdatedNormalized = 0.5;
                
                // Number of votes is most important, but in case they are equal for two buckets, the normalized
                // update time resolves the tie by reducing the weight of a later-updated bucket to a greater extent.
                $buckets[$i]->setWeight(10 * count($buckets[$i]->getVotes()) - $lastUpdatedNormalized);
            }
        }
    }
    
    
    // Weighs every bucket based on the number of votes it has, and gives the highest weight to a "No Lynch" bucket
    // if there is no relative majority.
    class RelativeMajorityRequiredWeigher extends Weigher {
        public function apply(array /* of Bucket */ &$buckets) {
            // Weigh buckets based on their number of votes, and keep track of the max. number of votes.
            $maxVotes = 0;
            foreach($buckets as $bucket) {
                $votesTotal = count($bucket->getVotes());
                if($votesTotal > $maxVotes)
                    $maxVotes = $votesTotal;
                $bucket->setWeight($votesTotal);
            }
            
            // Check if there is only one bucket with the highest number of votes.
            $bucketsWithMaxVotes = 0;
            foreach($buckets as $bucket) {
                $votesTotal = count($bucket->getVotes());
                if($votesTotal === $maxVotes && (++$bucketsWithMaxVotes) > 1) {
                    // Find a no-lynch bucket or create one.
                    $noLynchBucket = null;
                    foreach($buckets as $b) {
                        if($b->getTarget() === null) {
                            $noLynchBucket = $b;
                            break;
                        }
                    }
                    if($noLynchBucket === null) {
                        $noLynchBucket = new Bucket(null);
                        $buckets[] = $noLynchBucket;
                    }
                    
                    // And give it the highest possible weight.
                    $noLynchBucket->setWeight(+INF);
                    return;
                }
            }
        }
    }