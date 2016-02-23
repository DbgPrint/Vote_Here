<?php
    /**
     * RdtAPI
     * Copyright (C) 2014-2015 DbgPrint <dbgprintex@gmail.com>
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
    
    require_once(__DIR__ . '/Ratelimiter.php');
    require_once(__DIR__ . '/CURLException.php');
    require_once(__DIR__ . '/HTTPException.php');
    
    final class Client {
        private $useragent = 'RdtAPI/1.2';
        private $baseURL = 'https://www.reddit.com';
        private $baseOAuthURL = 'https://oauth.reddit.com';
        
        private $cookies = []; // url-encoded
        private $authHeader = null;
        private $authHeaderExpirationTime = null;
        
        const MIN_REQUEST_DELAY = 2; // sec
        private $ratelimiter;
        
        private $getCache = [];
        
        public function __construct() {
            $this->ratelimiter = new Ratelimiter();
            $this->ratelimiter->setMinDelay(self::MIN_REQUEST_DELAY);
        }
        
        // Sets useragent.
        public function setUseragent(/* string */ $useragent) {
            $this->useragent = $useragent;
        }
        
        // Performs a GET request and returns the response.
        public function get(/* string */ $url, array $parameters = [], /* bool */ $useOAuth = false,
                            /* bool */ $useCache = true) {
            $cacheId = md5(serialize(func_get_args()));
            if($useCache && isset($this->getCache[$cacheId]))
                return $this->getCache[$cacheId];
            
            $parameters['nocache'] = time(); // prevent Cloudfare from caching the request for us
            
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($parameters);
            return ($this->getCache[$cacheId] = $this->request($url, [ CURLOPT_HTTPGET => true ], $useOAuth));
        }
        
        // Performs a POST request and returns the response.
        public function post(/* string */ $url, array $parameters = [], /* bool */ $useOAuth = false) {
            return $this->request($url, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $parameters
            ], $useOAuth);
        }
        
        // Authorizes the client.
        public function authorize(/* string */ $clientId, /* string */ $clientSecret,
                                  /* string */ $username, /* string */ $password) {
            $response = $this->request('/api/v1/access_token', [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => [
                    'grant_type' => 'password',
                    'username' => $username,
                    'password' => $password
                ],
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_USERPWD => $clientId . ':' . $clientSecret
            ], false);
            $this->authHeader = 'Authorization: ' . $response->token_type . ' ' . $response->access_token;
            $this->authHeaderExpirationTime = time() + $response->expires_in;
        }
        
        // Performs a request and returns the response.
        private function request(/* string */ $url, /* string */ $curlOptions, /* bool */ $useOAuth = false) {
            $url = ($useOAuth ? $this->baseOAuthURL : $this->baseURL) . $url;
            $curlSession = curl_init($url);
            curl_setopt_array($curlSession, [
                CURLOPT_SSLVERSION => 4,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 2
            ]);
            curl_setopt_array($curlSession, $curlOptions);
            
            curl_setopt($curlSession, CURLOPT_HEADER, true);
            curl_setopt($curlSession, CURLOPT_RETURNTRANSFER, true);
            
            curl_setopt($curlSession, CURLOPT_USERAGENT, $this->useragent);
            if($useOAuth && $this->authHeader && time() < $this->authHeaderExpirationTime)
                curl_setopt($curlSession, CURLOPT_HTTPHEADER, [ $this->authHeader ]);
            
            $this->ratelimiter->waitForNextAction();
            $rawResponse = curl_exec($curlSession);
            if(!$rawResponse)
                throw new CURLException($curlSession, $url);
            
            $headerLength = curl_getinfo($curlSession, CURLINFO_HEADER_SIZE);
            $headers = explode("\n", substr($rawResponse, 0, $headerLength));
            $response = substr($rawResponse, $headerLength);
            
            $statusCode = $reasonPhrase = null;
            $ratelimitRemaining = $ratelimitReset = null;
            foreach($headers as $header) {
                $header = trim($header);
                if(strlen($header) === 0)
                    continue;
                
                if(self::stringStartsWith($header, 'HTTP/')) {
                    $statusLine = explode(' ', $header, 3);
                    if(count($statusLine) !== 3)
                        continue; // Invalid status line
                    list(/* unused */, $statusCode, $reasonPhrase) = $statusLine;
                    $statusCode = (int)$statusCode;
                    continue;
                }
                
                if(self::stringStartsWith(strtolower($header), 'set-cookie: ')) {
                    $start = strpos($header, ' ') + 1;
                    $end = strpos($header, ';');
                    if($end === false)
                        $end = strlen($header);
                    $definition = explode('=', substr($header, $start, $end - $start), 2);
                    if(count($definition) !== 2)
                        continue; // Invalid definition
                    $this->cookies[$definition[0]] = $definition[1];
                    continue;
                }
                
                if(self::stringStartsWith(strtolower($header), 'x-ratelimit-remaining: ')) {
                    $this->ratelimiter->setPeriodActionsLeft((int)substr($header, strpos($header, ' ') + 1));
                    continue;
                }
                if(self::stringStartsWith(strtolower($header), 'x-ratelimit-reset: ')) {
                    $this->ratelimiter->setPeriodResetTime(time() + (int)substr($header, strpos($header, ' ') + 1));
                    continue;
                }
            }
            
            $this->ratelimiter->setNextAction();
            
            if($statusCode === null || $reasonPhrase === null)
                throw new HTTPException('No Status', 0, $url);
            if($statusCode < 200 || $statusCode > 299)
                throw new HTTPException($reasonPhrase, $statusCode, $url);
            
            curl_close($curlSession);
            return json_decode($response);
        }
        
        /**
         * Returns true if $haystack starts with $needle.
         * @param string $haystack
         * @param string $needle
         * @return bool
         */
        private static function stringStartsWith($haystack, $needle) {
            return substr($haystack, 0, strlen($needle)) === $needle;
        }
        
        /**
         * Flattens a given thing tree (of private messages or comments).
         * @param array &$array A reference to a flattened tree.
         * @param object $listing A listing with things.
         * @return void
         */
        public static function flattenThingTree(&$array, $listing) {
            if(!isset($listing->data) || !isset($listing->data->children))
                return;
            foreach($listing->data->children as $thing) {
                $array[] = &$thing->data;
                if(isset($thing->data->replies))
                    self::flattenThingTree($array, $thing->data->replies);
            }
        }
    }