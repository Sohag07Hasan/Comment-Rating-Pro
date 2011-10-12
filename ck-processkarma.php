<?php
	/*
	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.
	
	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

Access format:
curl -D Header test.xxx.com/wp-content/plugins/comment-rating-pro/ck-processkarma.php?id=3\&action=add\&path=123\&imgIndex=12
	*/

require_once('../../../wp-config.php');
require_once('../../../wp-includes/functions.php');

// CSRF attach protection. Check the Referal field to be the same
// domain of the script  
/* Some browser doesn't send referrer field, this has to be disabled.
$refURL = parse_url($_SERVER['HTTP_REFERER']);
if ($_SERVER['SERVER_NAME'] !== $refURL['host']) {
   die('error|Possible CSRF attack, Comment Rating not changed.');
}
*/

$k_id = strip_tags($wpdb->escape($_GET['id']));
$k_action = strip_tags($wpdb->escape($_GET['action']));
$k_path = strip_tags($wpdb->escape($_GET['path']));
$k_imgIndex = strip_tags($wpdb->escape($_GET['imgIndex']));
$table_name = $wpdb->prefix . 'comment_rating';
$comment_table_name = $wpdb->prefix . 'comments';

// prevent SQL injection
if (!is_numeric($k_id)) die('error|Query error');

if($k_id && $k_action && $k_path) {
    //Check to see if the comment id exists and grab the rating
    $query = "SELECT * FROM `$table_name` WHERE ck_comment_id = $k_id";
    $result = mysql_query($query);

	if(!$result) { die('error|mysql: '.mysql_error()); }
	
   if(mysql_num_rows($result))
	{
      $duplicated = 0;  // used as a counter to off set duplicated votes
      $ip = getenv("HTTP_X_FORWARDED_FOR") ? getenv("HTTP_X_FORWARDED_FOR") : getenv("REMOTE_ADDR");
      if($row = @mysql_fetch_assoc($result))
      {  
         // IP are stored as IP,IP,..., ID are stored as: ,ID=
         // This allows coexistance of both format
         if (get_option('ckrating_voting_fraud') == 2) {
            if(strstr($row['ck_ips'], $ip))
            {
               // die('error|You have already voted on this item!'); 
               // Just don't count duplicated votes
               $duplicated = 1;
               $ck_ips = $row['ck_ips'];
            }
            else {
               $ck_ips = $row['ck_ips'] . ',' . $ip; // IPs are separated by ','
            }
         }
         elseif (get_option('ckrating_voting_fraud') == 4) {
            global $current_user;
            get_currentuserinfo();
            // ID are stored in format: ,ID=
            if(strstr($row['ck_ips'], ','.$current_user->ID.'=')) 
            {
               // die('error|You have already voted on this item!'); 
               // Just don't count duplicated votes
               $duplicated = 1;
               $ck_ips = $row['ck_ips'];
            }
            else {
               $ck_ips = $row['ck_ips'] . ',' . $current_user->ID . '=';
            }
         }
      }
		
      $total = $row['ck_rating_up'] - $row['ck_rating_down'];
      if($k_action == 'add') {
         $rating = $row['ck_rating_up'] + 1 - $duplicated;
         $direction = 'up';
         $total = $total + 1 - $duplicated;
      }
      elseif($k_action == 'subtract')
      {
         $rating = $row['ck_rating_down'] + 1 - $duplicated;
         $direction = 'down';
         $total = $total - 1 + $duplicated;
      } else {
            die('error|Try again later'); //No action given.
      }
		
      if (!$duplicated)
      {
         $query = "UPDATE `$table_name` SET ck_rating_$direction = '$rating', ck_ips = '" . $ck_ips  . "' WHERE ck_comment_id = $k_id";
         $result = mysql_query($query); 
         if(!$result)
         {
            // die('error|query '.$query);
            die('error|Query error');
         }
          
         // Now duplicated votes will not 
         if(!mysql_affected_rows())
         {
            die('error|affected '. $rating);
         }
         
         $karma_modified = 0;
         if (get_option('ckrating_karma_type') == 'likes' && $k_action == 'add') {
            $karma_modified = 1; $karma = $rating;
         }
         if (get_option('ckrating_karma_type') == 'dislikes' && $k_action == 'subtract') {
            $karma_modified = 1; $karma = $rating;
         }
         if (get_option('ckrating_karma_type') == 'both') {
            $karma_modified = 1; $karma = $total;
         }

         if ($karma_modified) {
            $query = "UPDATE `$comment_table_name` SET comment_karma = '$karma' WHERE comment_ID = $k_id";
            $result = mysql_query($query); 
            if(!$result) die('error|Comment Query error');
         }
      }
   } else {
        die('error|Comment doesnt exist'); //Comment id not found in db, something wrong ?
   }
} else {
    die('error|Fatal: html format error');
}

// Add the + sign, 
if ($total > 0) { $total = "+$total"; }

// set cookie for fraud control.
// Cookie is just a list of comment ID separated by a space.
// ckrating_voting_fraud 1: no prevention; 2: by IP; 3: by cookie; 4: by user ID
if ( get_option('ckrating_voting_fraud') == 3 ) {
   if (get_option('ckrating_cookie_expire') == 1)
      $expireTime = time() + 3600*24*get_option('ckrating_cookie_days');
   else 
      $expireTime = get_option('ckrating_cookie_time');

   if (isset($_COOKIE['Comment_Rating'])) {
      if (isset( $_COOKIE['Comment_Rating'])) {
         $value = $_COOKIE['Comment_Rating'] . ",$k_id";
         setcookie("Comment_Rating", $value, $expireTime, "/");
      }
      else
         setcookie("Comment_Rating", "$k_id", $expireTime, "/");
   }
   else
      setcookie("Comment_Rating", "$k_id", $expireTime, "/");
}

//This sends the data back to the js to process and show on the page
// The dummy field will separate out any potential garbage that
// WP-superCache may attached to the end of the return.
echo("done|$k_id|$rating|$k_path|$direction|$total|$k_imgIndex|dummy");
?>
