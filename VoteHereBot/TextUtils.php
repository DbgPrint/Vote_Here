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
	
	final class TextUtils {
        // Finds a string in $haystack that exactly matches $needle, ignoring capitalization.
        public static function findStringCaseInsensitive($haystack, $needle) {
            // TODO: http://stackoverflow.com/questions/4168107/case-insensitive-array-search
            $needleLowercase = strtolower($needle);
            foreach($haystack as $hay) {
                if(strtolower($hay) === $needleLowercase)
                    return $hay;
            }
            return null;
        }
    }