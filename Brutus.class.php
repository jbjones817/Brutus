<?php

/**
* Brutus - Comprehensive password testing made easy
*
* A simple, yet comprehensive password grading and validation class which
* utilizes tried and tested methods for quantifying a password's strength
* as well as enforcing a security policy condusive to strong passwords.
*
* Includes a dictionary of the 10k most common passwords from Mike Burnett
* (https://xato.net/passwords/more-top-worst-passwords) as well as an extensive
* alphabetical dictionary of common terms. Checking passwords against a library
* like this helps to prevent users from choosing passwords that wouldn't stand
* up to even the simplest dictionary attacks. We also check the password for
* "leetspeak" substitutions. While on the surface they may seem to make the
* password stronger, their predictability actually makes it so that they do
* little more for your password than using plain text characters.
*
* By combining the methods above with methods to measure the shannon entropy
* of the password as well as simulating a brute force attack, we can more
* accurately measure/grade the "strength" of a given password. This can help
* you to implement a more secure password policy by disallowing weak passwords
* that wouldn't stand up to brute force attacks.
*
*
* @author Josh Jones
* @version 1.0
* @license GPL3
*
*
*
* Example Usage:
* * * * * * * * *
* $brutus = new Brutus($args);
*
* if ($brutus->badPass($password, $id)) {
*   foreach ($brutus->showErrors() as $error) {
*     echo $error.'<br>';
*   }
* }
*
*/

class Brutus {

  /**
   * @var string $password Set to null at start, use param of primary method to modify
   */
  private $password = null;

  /**
   * @var bool $lookup Set to null at start, use param of __construct() to modify
   */
  private $lookup = null;

  /**
   * @var integer $hashpsec The simulated speed of attacker's system represented by
   * how many hashes it can crank out per second. 1 billion by default (worst case)
   */
  private $hashpsec = 1000000000;

  /**
   * @var string $dictionary The relative file path of the dictionary file to be used
   */
  private $dictionary = 'dictionary.txt';

  /**
   * @var string $commons The relative file path of the common password file to be used
   */
  private $commons = 'commons-freq.txt';

  /**
   * @var array $passlist An array of all possible permutations of a "leet" password
   */
  private $passlist = array();

  /**
   * @var array $rules An array of rules to govern how we should grade a password
   * (passed as parameter in the __construct() method)
   */
  private $rules = array();

  /**
   * @var array $errors This array will be filled with entries if any errors are found
   * during the grading of each password.
   */
  private $errors = array();

  /**
   * @var array $i18n A list of English strings to be matched to each $errors type
   * Custom strings for other languages can be passed in the __construct() method
   */
  private $i18n = array(
    'minlen'     => 'Password cannot be less than %s characters',
    'maxlen'     => 'Password cannot be greater than %s characters',
    'lower'      => 'Password must contain at least %s lowercase leter%s',
    'upper'      => 'Password must contain at least %s uppercase letter%s',
    'numeric'    => 'Password must contain at least %s number%s',
    'special'    => 'Password must contain at least %s special character%s',
    'identity'   => 'Password contains one or more personally identifiable tokens',
    'commons'    => 'Password was found in the list of most common passwords',
    'dictionary' => 'Password was found in the list of dictionary terms',
    'entropy'    => 'Password must have at least %s bits of entropy; Currently at %s',
    'brute'      => 'Password must survive %s days of brute force attempts; Currently at %s'
  );

  /**
   * @var array $charSets All possible character sets used in password(s) starting with simplest
   *
   * We start with the simplest character set (all numeric), and slowly work our way up increasing
   * the complexity of the character set gradually so as to err in the attacker's favor by reducing
   * the estimated keyspace needed to crack a particular password using brute force.
   */
  private $charSets = array(
    "0123456789", // numeric only
    "0123456789 ", // numeric + space
    "abcdefghijklmnopqrstuvwxyz", // lower alpha
    "abcdefghijklmnopqrstuvwxyz ", // lower alpha + space
    "abcdefghijklmnopqrstuvwxyz0123456789", // lower alphanumeric
    "abcdefghijklmnopqrstuvwxyz0123456789 ", // lower alphanumeric + space
    "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ", // mixed alpha
    "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ ", // mixed alpha + space
    "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789", // mixed alphanumeric
    "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 ", // mixed alphanumeric + space
    "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-=_+ ", // mixed alphanumeric + primary symbols
    "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-=_+[]\"{}|;':,./<>?`~", // mixed alphanumeric + all symbols
  );
  
  /**
   * @param array $args The list of optional arguments which will be assigned to the $rules array
   * @param mixed $i18n Set to NULL by default. If not null, should contain array to replace original strings
   * @throws Exception If $i18n array does not contain the correct number of entries
   */
  public function __construct($args=array('minlen'=>10,'maxlen'=>50,'lookup'=>true,'lower'=>2,'upper'=>2,'numeric'=>1,'special'=>1,'diminishing'=>true,'entropy'=>30,'brute'=>60,'usefile'=>null,'dataset'=>'commons'), $i18n=null) {
    foreach ($args as $arg => $val) {
      $this->rules[$arg] = $val;
    }
    if (isset($i18n) && count($i18n) != 11) {
      throw new Exception(sprintf('Internationalization array requires 11 entries; %s supplied.', count($i18n)));
    }
    else {
      foreach ($i18n as $k => $v) {
        $this->i18n[$k] = $v;
      }
    }
  }

  /**
   * This is the primary method associated with this class, but all it does it reference the other methods
   * 
   * @param string $password This string will replace the original NULL value of $this->password property
   * @param mixed $id Should be an array (if set) of user-specific personally identifiable tokens
   * @return bool Assumes FALSE (meaning NOT a bad password), $errors array sets to TRUE
   */
  public function badPass($password, $id) {
    $this->password = $password;
    $this->rules['identity'] = $id;
    $this->checkLength();
    $this->checkComp();
    $this->check1337();
    $this->wordLookup();
    $this->userDetails();
    $this->getNISTbits();
    $this->simBrute();
    if (count($this->errors) > 0) {
      return true;
    }
    return false;
  }

  /**
   * @return array The $errors array (defaults to empty array)
   */
  public function showErrors() {
    return $this->errors;
  }

  /**
   * Checks the length of the password and compares it against the corresponding rule in the $rules array
   */
  private function checkLength() {
    if (strlen($this->password) < $this->rules['minlen']) {
      $this->errors[] = sprintf($this->i18n['minlen'], $this->rules['minlen']);
    }
    else if (strlen($this->password) > $this->rules['maxlen']) {
      $this->errors[] = sprintf($this->i18n['maxlen'], $this->rules['maxlen']);
    }
  }

  /**
   * Checks the composition of the password and compares it against the corresponding rule in the $rules array
   */
  private function checkComp() {
    if (preg_match_all('/[a-z]/', $this->password, $lower) < $this->rules['lower']) {
      $this->errors[] = sprintf($this->i18n['lower'], $this->rules['lower'], ($this->rules['lower'] > 1) ? 's' : '');
    }
    if (preg_match_all('/[A-Z]/', $this->password, $upper) < $this->rules['upper']) {
      $this->errors[] = sprintf($this->i18n['upper'], $this->rules['upper'], ($this->rules['upper'] > 1) ? 's' : '');
    }
    if (preg_match_all('/[0-9]/', $this->password, $numbers) < $this->rules['numeric']) {
      $this->errors[] = sprintf($this->i18n['numeric'], $this->rules['numeric'], ($this->rules['numeric'] > 1) ? 's' : '');
    }
    if (preg_match_all('/[\W_]/', $this->password, $special) < $this->rules['special']) {
      $this->errors[] = sprintf($this->i18n['special'], $this->rules['special'], ($this->rules['special'] > 1) ? 's' : '');
    }
  }

  private function check1337() {
    $leet = array(
      '@'=>array('a', 'o'), '4'=>array('a'),
      '8'=>array('b'), '3'=>array('e'),
      '1'=>array('i', 'l'), '!'=>array('i','l','1'),
      '0'=>array('o'), '$'=>array('s','5'),
      '5'=>array('s'), '6'=>array('b', 'd'), '7'=>array('t')
    );
    $map = array();
    $pass_array = str_split(strtolower($this->password));
    foreach($pass_array as $i => $char) {
      $map[$i][] = $char;
      foreach ($leet as $pattern => $replace) {
        if ($char === (string)$pattern) {
          for($j=0,$c=count($replace); $j<$c; $j++) {
            $map[$i][] = $replace[$j];
          }
        }
      }
    }
    $this->passlist = $this->populateList($map);
  }

  private function populateList(&$map, $old = array(), $index = 0) {
    $new = array();
    foreach ($map[$index] as $char) {
      $c = count($old);
      if ($c == 0) {
        $new[] = $char;
      }
      else {
        for ($i=0,$c=count($old); $i<$c; $i++) {
          $new[] = @$old[$i].$char;
        }
      }
    }
    unset($old);
    $r = ($index == count($map)-1) ? $new : $this->populateList($map, $new, $index + 1);
    return $r;
  }

  private function wordLookup() {
    if ($this->rules['lookup']) {
      if (isset($this->rules['usefile'])) {
        if ($this->rules['dataset'] == 'commons') {
          $use_file = $this->commons;
        }
        else if ($this->rules['dataset'] == 'dictionary') {
          $use_file = $this->dictionary;
        }
        else if ($this->rules['dataset'] == 'both') {
          $use_file = array($this->commons, $this->dictionary);
        }
        else {
          throw new Exception('Lookup file not specified');
        }
        if (is_array($use_file)) {
          foreach ($use_file as $file_name) {
            if (!file_exists($file_name)) {
              throw new Exception('Lookup file not found');
            }
            if (!is_readable($file_name)) {
              throw new Exception('Lookup file not readable (check permissions)');
            }
            $file = fopen($file_name,'rb');
            $emsg = strpos($file_name, 'dictionary') ? 'dictionary' : 'commons';
            while (!feof($file)) {
              $common = fgets($file);
              $common = trim($common);
              foreach ($this->passlist as $password) {
                $password = strtolower($password);
                if ($common == $password) {
                  $this->errors[] = $this->i18n[$emsg];
                  return;
                }
              }
            }
            fclose($file);
            unset($file, $text, $common);
          }
        }
        else {
          if (!file_exists($use_file)) {
            throw new Exception('Lookup file not found');
          }
          if (!is_readable($use_file)) {
            throw new Exception('Lookup file not readable (check permissions)');
          }
          $file = fopen($use_file,'rb');
          $emsg = strpos($use_file, 'dictionary') ? 'dictionary' : 'commons';
          while (!feof($file)) {
            $common = fgets($file);
            $common = trim($common);
            foreach ($this->passlist as $password) {
              $password = strtolower($password);
              if ($common == $password) {
                $this->errors[] = $this->i18n[$emsg];
                return;
              }
            }
          }
          fclose($file);
          unset($file, $text, $common);
        }
      }
      else {
        if ($this->rules['dataset'] == 'commons') {
          $table = 'commons';
        }
        else if ($this->rules['dataset'] == 'dictionary') {
          $table = 'words';
        }
        else {
          $table = array('commons', 'words');
        }
        try {
          $db = new PDO(BRUTUS_DBTYPE.':host='.BRUTUS_DBHOST.';dbname='.BRUTUS_DBNAME, BRUTUS_DBUSER, BRUTUS_DBPASS);
          $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
          $matches = 0;
          foreach ($this->passlist as $password) {
            if (is_array($table)) {
              foreach ($table as $tbl) {
                $stmt = $db->prepare("SELECT count(*) FROM $tbl WHERE `text` = :pass");
                $stmt->bindParam(':pass', $password);
                $stmt->execute(); 
                if ($stmt->fetchColumn() > 0) {
                  if ($tbl == 'words') {
                    $this->errors[] = $this->i18n['dictionary'];
                    return;
                  }
                  else {
                    $this->errors[] = $this->i18n['commons'];
                    return;
                  }
                }
              }
            }
            else {
              $stmt = $db->prepare("SELECT count(*) FROM $table WHERE `text` = :pass");
              $stmt->bindParam(':pass', $password);
              $stmt->execute();
              if ($stmt->fetchColumn() > 0) {
                $this->errors[] = $this->i18n[$table];
                return;
              }
            }
          }
        }
        catch (PDOException $e) {
          throw new Exception($e->getMessage());
        }
      }
      $db = null;
    }
    return;
  }

  private function userDetails() {
    if (isset($this->rules['identity'])) {
      foreach ($this->rules['identity'] as $token) {
        foreach ($this->passlist as $password) {
          if (preg_match("/$token/i", $password)) {
            $this->errors[] = $this->i18n['identity'];
            return;
          }
        }
      }
    }
  }

  /**
   * Here we use the original NIST algorithm for calculating password entropy or
   * a modified version of it (depending on the value of $this->rules['diminishing'])
   * to calculate the estimated entropy of the password string.
   *
   * @return int A number representing how many bits of entropy the password has
   */
  private function getNISTbits() {

    $bits = $cnt = 0;
    $length = strlen($this->password);
    $char_map = str_split($this->password);
    $char_arr = array_fill(0, 256, 1);

    // Run the original NIST algorithm which
    // has no penalty for repeated characters
    if (!$this->rules['diminishing']) {
      foreach ($char_map as $char) {
        $cnt++;
        if ($cnt == 1) {
          $bits += 4;
        }
        elseif ($cnt > 1 && $cnt <= 8) {
          $bits += 2;
        }
        elseif ($cnt > 8 && $cnt <= 20) {
          $bits += 1.5;
        }
        else {
          $bits += 1;
        }
      }
    }

    // Run the modified NIST algorithm which
    // penalizes you for repeated characters
    // (diminishing returns)
    else {
      for ($cnt = 0; $cnt < $length; $cnt++) {
        $tmp = ord(substr($password, $cnt, 1));
        if ($cnt == 1) {
          $bits += 4;
        }
        elseif ($cnt > 1 && $cnt <= 8) {
          $bits += $char_arr[$tmp] * 2;
        }
        elseif ($cnt > 8 && $cnt <= 20) {
          $bits += $char_arr[$tmp] * 1.5;
        }
        else {
          $bits += $char_arr[$tmp];
        }
        $char_arr[$tmp] *= 0.75;
      }
    }

    // According to the NIST guidelines, an additional
    // 6 bits can be granted if the password contains
    // a combination of mixed case, numbers, and symbols.
    // We assign each of these a value of 1.5 bits here.
    if (preg_match_all('/[A-Z]/', $this->password, $upper) >= $this->rules['upper'])  $bits += 1.5;
    if (preg_match_all('/[a-z]/', $this->password, $lower) >= $this->rules['lower'])  $bits += 1.5;
    if (preg_match_all('/[0-9]/', $this->password, $numbs) >= $this->rules['numeric'])  $bits += 1.5;
    if (preg_match_all('/[\W_]/', $this->password, $specs) >= $this->rules['special'])  $bits += 1.5;

    if ($bits < $this->rules['entropy']) {
      $this->errors[] = sprintf($this->i18n['entropy'], $this->rules['entropy'], $bits);
    }
  }

  /**
   * The following method was taken directly from the Mellt class by ravisorg (https://github.com/ravisorg/Mellt)
   *
   * @author ravisorg
   * Copyright (c) 2012, ravisorg
   * All rights reserved.
   *
   * @license BSD
   * Redistribution and use in source and binary forms, with or without
   * modification, are permitted provided that the following conditions are met:
   *     * Redistributions of source code must retain the above copyright
   *       notice, this list of conditions and the following disclaimer.
   *     * Redistributions in binary form must reproduce the above copyright
   *       notice, this list of conditions and the following disclaimer in the
   *       documentation and/or other materials provided with the distribution.
   *     * Neither the name of the Travis Richardson nor the names of its 
   *       contributors may be used to endorse or promote products derived 
   *       from this software without specific prior written permission.
   *
   * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
   * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
   * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
   * DISCLAIMED. IN NO EVENT SHALL TRAVIS RICHARDSON BE LIABLE FOR ANY
   * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
   * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
   * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
   * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
   * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
   * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
   *
   * @return int The number of days it would take to brute force the password
   */
  private function simBrute() {
    $base = ''; $baseKey = NULL;
    $length = strlen($this->password);
    for ($t = 0; $t < $length; $t++) {
      $char = $this->password[$t];
      $foundChar = false;
      foreach ($this->charSets as $characterSetKey=>$characterSet) {
        if ($baseKey<=$characterSetKey && strpos($characterSet,$char)!==false) {
          $baseKey = $characterSetKey;
          $base = $characterSet;
          $foundChar = true;
          break;
        }
      }
      // If the character we were looking for wasn't anywhere in any of the
      // character sets, assign the largest (last) character set as default.
      if (!$foundChar) {
        $base = end($this->CharacterSets);
        break;
      }
    }
    
    unset($baseKey);
    unset($foundChar);

    // Starting at the first character, figure out it's position in the character set
    // and how many attempts will take to get there. For example, say your password
    // was an integer (a bank card PIN number for example):
    // 0 (or 0000 if you prefer) would be the very first password they attempted by the attacker.
    // 9999 would be the last password they attempted (assuming 4 characters).
    // Thus a password/PIN of 6529 would take 6529 attempts until the attacker found
    // the proper combination. The same logic words for alphanumeric passwords, just
    // with a larger number of possibilities for each position in the password. The
    // key thing to note is the attacker doesn't need to test the entire range (every
    // possible combination of all characters) they just need to get to the point in
    // the list of possibilities that is your password. They can (in this example)
    // ignore anything between 6530 and 9999. Using this logic, 'aaa' would be a worse
    // password than 'zzz', because the attacker would encounter 'aaa' first.
    $attempts = 0;
    $charactersInBase = strlen($base);
    for ($position = 0; $position < $length; $position++) {
      // We power up to the reverse position in the string. For example, if we're trying
      // to hack the 4 character PING code in the example above:
      // First number * (number of characters possible in the charset ^ length of password)
      // ie: 6 * (10^4) = 6000
      // then add that same equation for the second number:
      // 5 * (10^3) = 500
      // then the third numbers
      // 2 * (10^2) = 20
      // and add on the last number
      // 9
      // Totals: 6000 + 500 + 20 + 9 = 6529 attempts before we encounter the correct password.
      $powerOf = $length - $position - 1;
      // Character position within the base set. We add one on because strpos is base
      // 0, we want base 1.
      $charAtPosition = strpos($base,$this->password[$position])+1;
      // If we're at the last character, simply add it's position in the character set
      // this would be the "9" in the pin code example above.
      if ($powerOf==0) {
        $attempts = bcadd($attempts,$charAtPosition);
      }
      // Otherwise we need to iterate through all the other characters positions to
      // get here. For example, to find the 5 in 25 we can't just guess 2 and then 5
      // (even though Hollywood seems to insist this is possible), we need to try 0,1,
      // 2,3...15,16,17...23,24,25 (got it).
      else {
        // This means we have to try every combination of values up to this point for
        // all previous characters. Which means we need to iterate through the entire
        // character set, X times, where X is our position -1. Then we need to multiply
        // that by this character's position.

        // Multiplier is the (10^4) or (10^3), etc in the pin code example above.
        $multiplier = bcpow($charactersInBase,$powerOf);
        // New attempts is the number of attempts we're adding for this position.
        $newAttempts = bcmul($charAtPosition,$multiplier);
        // Add that on to our existing number of attempts.
        $attempts = bcadd($attempts,$newAttempts);
      }
    }
    
    // We can (worst case) try one billion passwords per second. Calculate how many days
    // it will take us to get to the password using only brute force attempts.
    $perDay = bcmul($this->hashpsec,60*60*24);

    // This allows us to calculate a number of days to crack. We use days because anything
    // that can be cracked in less than a day is basically useless, so there's no point in
    // having a smaller granularity (hours for example).
    $days = bcdiv($attempts,$perDay);

    // If it's going to take more than a billion days to crack, just return a billion. This
    // helps when code outside this function isn't using bcmath. Besides, if the password
    // can survive 2.7 million years it's probably ok.
    if (bccomp($days,1000000000)==1) {
      $days = 1000000000;
    }
    
    if ($days < $this->rules['brute']) {
      $this->errors[] = sprintf($this->i18n['brute'], $this->rules['brute'], $days);
    }
  }
}