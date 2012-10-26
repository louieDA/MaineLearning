<?php

add_action('admin_menu', 'relevanssi_menu');
add_filter('the_posts', 'relevanssi_query');
add_action('save_post', 'relevanssi_edit', 99, 1);				// thanks to Brian D Gajus
add_action('delete_post', 'relevanssi_delete');
add_action('comment_post', 'relevanssi_comment_index'); 	//added by OdditY
add_action('edit_comment', 'relevanssi_comment_edit'); 		//added by OdditY 
add_action('delete_comment', 'relevanssi_comment_remove'); 	//added by OdditY
add_action('wp_insert_post', 'relevanssi_insert_edit', 99, 1 ); // added by lumpysimon
// BEGIN added by renaissancehack
// *_page and *_post hooks do not trigger on attachments
add_action('delete_attachment', 'relevanssi_delete');
add_action('add_attachment', 'relevanssi_publish');
add_action('edit_attachment', 'relevanssi_edit');
// When a post status changes, check child posts that inherit their status from parent
add_action('transition_post_status', 'relevanssi_update_child_posts',99,3);
// END added by renaissancehack
add_action('init', 'relevanssi_init');
add_action('init', 'relevanssi_check_old_data', 99);
add_filter('relevanssi_hits_filter', 'relevanssi_wpml_filter');
add_filter('posts_request', 'relevanssi_prevent_default_request', 9, 2 );
add_filter('relevanssi_remove_punctuation', 'relevanssi_remove_punct');
add_filter('relevanssi_post_ok', 'relevanssi_default_post_ok', 10, 2);
add_filter('relevanssi_query_filter', 'relevanssi_limit_filter');
add_filter('query_vars', 'relevanssi_query_vars');

global $relevanssi_variables;
register_activation_hook($relevanssi_variables['file'], 'relevanssi_install');
$plugin_dir = dirname(plugin_basename($relevanssi_variables['file']));
load_plugin_textdomain('relevanssi', false, $plugin_dir);

function relevanssi_init() {
	global $pagenow, $relevanssi_variables;

	isset($_POST['index']) ? $index = true : $index = false;
	if (!get_option('relevanssi_indexed') && !$index) {
		function relevanssi_warning() {
			RELEVANSSI_PREMIUM ? $plugin = 'relevanssi-premium' : $plugin = 'relevanssi';
			echo "<div id='relevanssi-warning' class='updated fade'><p><strong>"
			   . sprintf(__('Relevanssi needs attention: Remember to build the index (you can do it at <a href="%1$s">the
			   settings page</a>), otherwise searching won\'t work.'), "options-general.php?page=" . $plugin . "/relevanssi.php")
			   . "</strong></p></div>";
		}
		add_action('admin_notices', 'relevanssi_warning');
	}
	
	if (!function_exists('mb_internal_encoding')) {
		function relevanssi_mb_warning() {
			echo "<div id='relevanssi-warning' class='updated fade'><p><strong>"
			   . "Multibyte string functions are not available. Relevanssi may not work well without them. "
			   . "Please install (or ask your host to install) the mbstring extension."
			   . "</strong></p></div>";
		}
		if ( 'options-general.php' == $pagenow and isset( $_GET['page'] ) and plugin_basename($relevanssi_variables['file']) == $_GET['page'] )
			add_action('admin_notices', 'relevanssi_mb_warning');
	}

	if (!wp_next_scheduled('relevanssi_truncate_cache')) {
		wp_schedule_event(time(), 'daily', 'relevanssi_truncate_cache');
		add_action('relevanssi_truncate_cache', 'relevanssi_truncate_cache');
	}

	if (get_option('relevanssi_highlight_docs', 'off') != 'off') {
		add_filter('the_content', 'relevanssi_highlight_in_docs', 11);
	}
	if (get_option('relevanssi_highlight_comments', 'off') != 'off') {
		add_filter('comment_text', 'relevanssi_highlight_in_docs', 11);
	}

	return;
}

function relevanssi_menu() {
	global $relevanssi_variables;
	RELEVANSSI_PREMIUM ? $name = "Relevanssi Premium" : $name = "Relevanssi";
	add_options_page(
		$name,
		$name,
		'manage_options',
		$relevanssi_variables['file'],
		'relevanssi_options'
	);
	add_dashboard_page(
		__('User searches', 'relevanssi'),
		__('User searches', 'relevanssi'),
		apply_filters('relevanssi_user_searches_capability', 'edit_pages'),
		$relevanssi_variables['file'],
		'relevanssi_search_stats'
	);
}

function relevanssi_query_vars($qv) {
	$qv[] = 'cats';
	$qv[] = 'tags';
	$qv[] = 'post_types';
	$qv[] = 'by_date';

	return $qv;
}

function relevanssi_create_database_tables($relevanssi_db_version) {
	global $wpdb;
	
	require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

	$charset_collate_bin_column = '';
	$charset_collate = '';

	if (!empty($wpdb->charset)) {
        $charset_collate_bin_column = "CHARACTER SET $wpdb->charset";
		$charset_collate = "DEFAULT $charset_collate_bin_column";
	}
	if (strpos($wpdb->collate, "_") > 0) {
        $charset_collate_bin_column .= " COLLATE " . substr($wpdb->collate, 0, strpos($wpdb->collate, '_')) . "_bin";
        $charset_collate .= " COLLATE $wpdb->collate";
    } else {
    	if ($wpdb->collate == '' && $wpdb->charset == "utf8") {
	        $charset_collate_bin_column .= " COLLATE utf8_bin";
	    }
    }
    
	$relevanssi_table = $wpdb->prefix . "relevanssi";	
	$relevanssi_stopword_table = $wpdb->prefix . "relevanssi_stopwords";
	$relevanssi_log_table = $wpdb->prefix . "relevanssi_log";
	$relevanssi_cache = $wpdb->prefix . 'relevanssi_cache';
	$relevanssi_excerpt_cache = $wpdb->prefix . 'relevanssi_excerpt_cache';

	if(get_option('relevanssi_db_version') != $relevanssi_db_version) {
		if ($relevanssi_db_version == 1) {
			if($wpdb->get_var("SHOW TABLES LIKE '$relevanssi_table'") == $relevanssi_table) {
				$sql = "DROP TABLE $relevanssi_table";
				$wpdb->query($sql);
			}
			delete_option('relevanssi_indexed');
		}
	
		$sql = "CREATE TABLE " . $relevanssi_table . " (doc bigint(20) NOT NULL DEFAULT '0', 
		term varchar(50) NOT NULL DEFAULT '0', 
		content mediumint(9) NOT NULL DEFAULT '0', 
		title mediumint(9) NOT NULL DEFAULT '0', 
		comment mediumint(9) NOT NULL DEFAULT '0', 
		tag mediumint(9) NOT NULL DEFAULT '0', 
		link mediumint(9) NOT NULL DEFAULT '0', 
		author mediumint(9) NOT NULL DEFAULT '0', 
		category mediumint(9) NOT NULL DEFAULT '0', 
		excerpt mediumint(9) NOT NULL DEFAULT '0', 
		taxonomy mediumint(9) NOT NULL DEFAULT '0', 
		customfield mediumint(9) NOT NULL DEFAULT '0', 
		mysqlcolumn mediumint(9) NOT NULL DEFAULT '0',
		taxonomy_detail longtext NOT NULL,
		customfield_detail longtext NOT NULL,
		mysqlcolumn_detail longtext NOT NULL,
		type varchar(210) NOT NULL DEFAULT 'post', 
		item bigint(20) NOT NULL DEFAULT '0', 
	    UNIQUE KEY doctermitem (doc, term, item)) $charset_collate";
		
		dbDelta($sql);

		$sql = "CREATE INDEX terms ON $relevanssi_table (term(20))";
		$wpdb->query($sql);

		$sql = "CREATE INDEX docs ON $relevanssi_table (doc)";
		$wpdb->query($sql);

		$sql = "CREATE TABLE " . $relevanssi_stopword_table . " (stopword varchar(50) $charset_collate_bin_column NOT NULL,
	    UNIQUE KEY stopword (stopword)) $charset_collate;";

		dbDelta($sql);

		$sql = "CREATE TABLE " . $relevanssi_log_table . " (id bigint(9) NOT NULL AUTO_INCREMENT, 
		query varchar(200) NOT NULL,
		hits mediumint(9) NOT NULL DEFAULT '0',
		time timestamp NOT NULL,
		user_id bigint(20) NOT NULL DEFAULT '0',
		ip varchar(40) NOT NULL DEFAULT '',
	    UNIQUE KEY id (id)) $charset_collate;";

		dbDelta($sql);
	
		$sql = "CREATE TABLE " . $relevanssi_cache . " (param varchar(32) $charset_collate_bin_column NOT NULL,
		hits text NOT NULL,
		tstamp timestamp NOT NULL,
	    UNIQUE KEY param (param)) $charset_collate;";

		dbDelta($sql);

		$sql = "CREATE TABLE " . $relevanssi_excerpt_cache . " (query varchar(100) $charset_collate_bin_column NOT NULL, 
		post mediumint(9) NOT NULL, 
		excerpt text NOT NULL, 
	    UNIQUE (query, post)) $charset_collate;";

		dbDelta($sql);

		if (RELEVANSSI_PREMIUM && get_option('relevanssi_db_version') < 12) {
			$charset_collate_bin_column = '';
			$charset_collate = '';
		
			if (!empty($wpdb->charset)) {
				$charset_collate_bin_column = "CHARACTER SET $wpdb->charset";
				$charset_collate = "DEFAULT $charset_collate_bin_column";
			}
			if (strpos($wpdb->collate, "_") > 0) {
				$charset_collate_bin_column .= " COLLATE " . substr($wpdb->collate, 0, strpos($wpdb->collate, '_')) . "_bin";
				$charset_collate .= " COLLATE $wpdb->collate";
			} else {
				if ($wpdb->collate == '' && $wpdb->charset == "utf8") {
					$charset_collate_bin_column .= " COLLATE utf8_bin";
				}
			}
			
			$sql = "ALTER TABLE $relevanssi_stopword_table MODIFY COLUMN stopword varchar(50) $charset_collate_bin_column NOT NULL";
			$wpdb->query($sql);
			$sql = "ALTER TABLE $relevanssi_log_table ADD COLUMN user_id bigint(20) NOT NULL DEFAULT '0'";
			$wpdb->query($sql);
			$sql = "ALTER TABLE $relevanssi_log_table ADD COLUMN ip varchar(40) NOT NULL DEFAULT ''";
			$wpdb->query($sql);
			$sql = "ALTER TABLE $relevanssi_cache MODIFY COLUMN param varchar(32) $charset_collate_bin_column NOT NULL";
			$wpdb->query($sql);
			$sql = "ALTER TABLE $relevanssi_excerpt_cache MODIFY COLUMN query(100) $charset_collate_bin_column NOT NULL";
			$wpdb->query($sql);
		}
		
		update_option('relevanssi_db_version', $relevanssi_db_version);
	}
	
	if ($wpdb->get_var("SELECT COUNT(*) FROM $relevanssi_stopword_table WHERE 1") < 1) {
		relevanssi_populate_stopwords();
	}
}
?>