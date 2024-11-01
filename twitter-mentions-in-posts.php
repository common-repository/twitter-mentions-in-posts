<?php
/*
Plugin Name: Twitter mentions in posts
Plugin URI: http://wordpress.fabulator.cz/zobrazte-twitter-zminky-o-vasich-clancich
Description: Show tweets about your posts right under them.
Version: 0.5
Author: Michal Ozogan
Author URI: https://twitter.com/fabulatorEN
*/

function tmip_create_menu(){
    add_options_page('Twitter mentions Settings', 'Twitter mentions Setting', "administrator", 'twitter-mentions-in-posts', 'tmip_twitter_follow_button_page'); 
    add_action('admin_init', 'tmip_register_mysettings');
    }
add_action('admin_menu', 'tmip_create_menu');

function tmip_register_mysettings(){
    register_setting('tmip-settings-group', 'tmip-embed');
    register_setting('tmip-settings-group', 'tmip-mention-text');
    register_setting('tmip-settings-group', 'tmip-num-of-tweets');
    register_setting('tmip-settings-group', 'tmip-automatic');
    }

/* SETTING PAGE */
function tmip_twitter_follow_button_page(){
?>
<div class="wrap">
    <h2>Twitter mentions in posts Settings</h2>
    <form method="post" action="options.php">
        <?php settings_fields('tmip-settings-group'); ?>
        <table class="form-table">
            <tr valign="top">
                <th scope="row">Embed tweets or regular HTML?</th>
                <td>
                    <fieldset>
                        <label>
                            <input <?php if(get_option('tmip-embed', "embed") == 'embed') echo "checked=\"checked\""; ?> name="tmip-embed" type="radio" value="embed" /> Embed</label>
                        <label>
                            <input <?php if(get_option('tmip-embed') == 'html') echo "checked=\"checked\""; ?> name="tmip-embed" type="radio" value="html" /> HTML</label>
                    </fieldset>
                </td>
            </tr>
            <tr valign="top">
                <th scope="row">Show tweets before comment form or add manually (by function tmip_show_tweets() )?</th>
                <td>
                    <fieldset>
                        <label>
                            <input <?php if(get_option('tmip-automatic', "comment_form") == 'comment_form') echo "checked=\"checked\""; ?> name="tmip-automatic" type="radio" value="comment_form" /> Before comment form</label>
                        <label>
                            <input <?php if(get_option('tmip-automatic') == 'manually') echo "checked=\"checked\""; ?> name="tmip-automatic" type="radio" value="manually" /> Manually</label>
                    </fieldset>
                </td>
            </tr>            
            <tr valign="top">
                <th scope="row">Title of box with mentions?</th>
                <td>
                    <input name="tmip-mention-text" type="text" value="<?php echo get_option('tmip-mention-text', 'Twitter mentions') ?>" />
                </td>
            </tr>       
            <tr valign="top">
                <th scope="row">How many tweets show under posts? Type -1 for infinite.</th>
                <td>
                    <input name="tmip-num-of-tweets" type="text" value="<?php echo get_option('tmip-num-of-tweets', '-1') ?>" />
                </td>
            </tr>
        </table>
        <p class="submit">
            <input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
        </p>
    </form>
</div>
<?php }
 
add_action('tmip_load_post_hook', 'tmip_load_post', 10, 1);

/* ENQUE TWITTER JAVASCRIPT FILES */

add_action('wp_enqueue_scripts', 'tmip_load_javascript_files');
function tmip_load_javascript_files() {
    if(get_option('tmip-embed') != 'html'){
        wp_register_script('twitter', "http://platform.twitter.com/widgets.js", array(), '1.0', true);
        wp_enqueue_script('twitter');
    }
}

/* EVERY OUR START CRON WHICH CONTROL IF EVERY POSTS CHECKING FOR NEW TWEETS */

add_action('tmip_hour_event', 'tmip_load_twitter');

function tmip_cron_activation() {
    if (!wp_next_scheduled('tmip_hour_event')) {
        wp_schedule_event(time(), 'hourly', 'tmip_hour_event');
        tmip_load_twitter();
    }
}

add_action('wp', 'tmip_cron_activation');

function tmip_search_twitter_iD($id, $array) {
    $found = false;
    if ($array) {
        foreach ($array as $key => $value) {
            if ($value['id'] == $id) {
                $found = true;
                break;
            }
        }
    }
    return $found;
}

/* LOAD TWEETS FOR SPECIFIC PAGE AND SAVE THEM TO DATABASE */

function tmip_load_post($id) {
    global $wpdb;
    $url = get_permalink($id);
    $ago = time() - Date("U", strtotime($wpdb->get_var("SELECT post_date FROM $wpdb->posts WHERE ID = '" . $id . "'")));
    $hour = 60 * 60;
    if ($ago < $hour * 24)
        $time = rand(60 * 20, 60 * 30);
    elseif ($ago < $hour * 24 * 2)
        $time = rand($hour * 2, $hour * 3);
    elseif ($ago < $hour * 24 * 7)
        $time = rand($hour * 12, $hour * 24);
    else
        $time = rand($hour * 48, $hour * 72);
    if ($time > 0)
        wp_schedule_single_event(time() + $time, 'tmip_load_post_hook', array('id' => $id));
    $tweets = json_decode(file_get_contents("http://search.twitter.com/search.json?q=" . urlencode($url . " -filter:retweets") . "&rpp=20&include_entities=true&result_type=mixed"));
    $mentions = get_post_meta($id, 'tmip_twitter_mentions', true);
    if ($tweets->results) {
        foreach ($tweets->results as $tweet) {
            $unixTime = strtotime($tweet->created_at);
            $tweetID = $tweet->id;
            if (!tmip_search_twitter_iD($tweetID, $mentions)) {
                $tweet_text = tmip_twitter_links($tweet->text);
                $mentions[$unixTime] = array('id' => $tweetID, 'content'=> $tweet_text, 'user' => $tweet->from_user, 'user_name' => $tweet->from_user_name, 'img' => $tweet->profile_image_url);
            }
        }
        ksort($mentions);
        update_post_meta($id, "tmip_twitter_mentions", $mentions);
    }
}

/* REPLACE PLAIN LINKS TO ACTIVE LINKS */

function tmip_twitter_links($tweetText) {
    $tweetText = preg_replace("/(http:\/\/)(.*?)\/([\w\.\/\&\=\?\-\,\:\;\#\_\~\%\+]*)/", "<a href='\\0'>" . ("\\0") . "</a>", $tweetText);
    $matches = array();
    preg_match_all("/\s*http:\/\/t.co([a-zA-Z0-9\/\*\-\_\?\&\;\%\=\.]*)/i", $tweetText, $matches);
    $tcoLinks = $matches[0];
    $founded = array();
    $originalLinks = array();
    foreach ($tcoLinks as $link) {
        if (!in_array($link, $founded)) {
            $original = tmip_return_tco_link($link);
            $originalLinks[] = $original;
            $founded[] = $link;
        }
    }
    $tweetText = str_replace($tcoLinks, $originalLinks, $tweetText);
    $tweetText = preg_replace("(@([a-zA-Z0-9\_]+))", "<a href=\"http://www.twitter.com/\\1\">\\0</a>", $tweetText);
    return preg_replace('/(^|\s)#(\w+)/u', '\1<a href="http://search.twitter.com/search?q=%23\2">#\2</a>', $tweetText);
}

/* FINAL FINAL URL URL SHORCUTTER T.CO */

function tmip_return_tco_link($url) {
    $curl = curl_init($url);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.6) Gecko/20070725 Firefox/2.0.0.6");
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HEADER, true);
    $return = curl_exec($ch);
    $info = curl_getinfo($ch);
    if ($info['http_code'] == 200) {
        $url = explode("URL=http", $return);
        $url = explode("\"", $url[1]);
        return "http" . $url[0];
    }
    else
        return false;
}

function tmip_load_twitter() {
    global $wpdb;
    foreach ($wpdb->get_results("SELECT ID FROM $wpdb->posts WHERE post_type='post' AND post_status='publish'") as $post) {
        if (!wp_next_scheduled('tmip_load_post_hook', array('id' => $post->ID))) {
            wp_schedule_single_event(time() + rand(60 * 10, 60 * 30), 'tmip_load_post_hook', array('id' => $post->ID));
        }
    }
}

add_action('init', 'tmip_include_tweets');

function tmip_include_tweets(){
    if(get_option('tmip-automatic', "comment_form") == 'comment_form'){
        add_action("comment_form_before", "tmip_show_tweets");
    }
}

function tmip_show_tweets(){
    echo tmip_get_tweets();
}

function tmip_get_tweets() {
    $return = '';
    $tweets = get_post_meta(get_the_ID(), 'tmip_twitter_mentions', true);
    $max_tweets = get_option("tmip-num-of-tweets", -1);
    $num = 0;
    $date_format = get_option('date_format') . " " . get_option('time_format');
    if ($tweets) {
        $tweets = array_reverse($tweets, true);
        $return .= "<h3>". get_option("tmip-mention-text", "Twitter mentions") ."</h3><div id='tweets'>";
        foreach ($tweets as $unix => $tweet) {
            if($num == $max_tweets) break;
            if(get_option('tmip-embed') != 'html'){ 
               $return .= '<center><blockquote class="twitter-tweet" data-cards="hidden"><p>'. $tweet['content'] .'</p>&mdash;  '. $tweet['user_name'] .' (@'. $tweet['user'] .') <a href="https://twitter.com/'. $tweet['user'] .'/status/'. $tweet['id'] .'">'. date($date_format, $unix) .'</a></blockquote></center>';
            }
            else {
                $return .= "<div class='tweet'>
                        <a href='http://twitter.com/" . $tweet['user'] . "'>
                            <img src='" . $tweet['img'] . "' style='float: left; margin: 0 10px 10px 0'/>
                        </a>
                        <p>
                            <strong><a href='http://twitter.com/" . $tweet['user'] . "'>@" . $tweet['user'] . "</a></strong>
                            <em>(" . date($date_format, $unix) . ")</em>
                            <br />" . $tweet['content'] . "
                        </p>
                        <br />
                        <div style='clear: both'></div>
                    </div>";
            }
            $num++;
        }
        $return .= "</div>";
    }
    return $return;
}
?>