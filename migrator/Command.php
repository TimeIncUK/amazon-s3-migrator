<?php

/**
* Handles the migration of the current images in WP to being stored on S3
*/
class S3_Migrator_Command extends WP_CLI_Command {


    // 113113  ipc_import_data,original-source,ipc_image_data,amazonS3_info

    /**
     * Handles the migration of the images in the supplied blog_id to S3
     *
     * ## OPTIONS
     *
     * domain=<domain>
     * : The S3 domain and bucket to replace the images with, e.g. s3-eu-west-1.amazonaws.com/testbucket
     * batch=<count>
     * : The number of records to test in one go. This defaults to 1000
     * type=<type>
     * : Only migrate a certain type. This can be `all`, `images`, `posts`, `postmeta`, or `options`
     * ignore-meta-keys=<ignore-meta-keys>
     * : Some post meta data is only for storage and doesn't need to be converted. This takes a csv of meta keys
     *
     * @synopsis --domain=<domain> [--batch=<count>] [--type=<type>] [--ignore-meta-keys=<ignore-meta-keys>]
     * @param array $positionalArgs The arguments supplied by the CLI, these are ignored by this function
     * @param array $args The additional options supplied by the CLI, in this case the batch count
     */
    public function Migrate(array $positionalArgs, array $args) {

        // the imported data is from a trustworthy source so disable this as they're too slow
        kses_remove_filters();

        // init vars
        $db = $this->GetDBObject();
        $limit = isset($args['batch']) ? $args['batch'] : 1000;
        $siteDomain = $args['domain'];
        $type = isset($args['type']) ? $args['type'] : 'all';
        $ignoreMeta = isset($args['ignore-meta-keys']) ? explode(',', $args['ignore-meta-keys']) : array();

        // migrate images separately from posts as they need special meta
        if ($type === 'all' || $type === 'images') {
            $this->MigrateImages($db, $limit, $siteDomain);
        }

        // migrate posts table
        if ($type === 'all' || $type === 'posts') {
            $this->MigratePosts($db, $limit, $siteDomain);
        }

        // migrate post meta table
        if ($type === 'all' || $type === 'postmeta') {
            $this->MigratePostMeta($db, $limit, $siteDomain, $ignoreMeta);
        }

        // migrate options table
        if ($type === 'all' || $type === 'options') {
            $this->MigrateOptions($db, $limit, $siteDomain);
        }
    }

    /**
     * Migrates the images that exist as post attachments
     *
     * @param wpdb $db The database object
     * @param int $limit The number of records to return each slice
     * @param string $siteDomain The domain to replace the images' domain with
     */
    public function MigrateImages(wpdb $db, $limit, $siteDomain) {
        // get a count of the attachments
        $sql = 'SELECT COUNT(id) AS counted FROM '.$db->posts.' WHERE post_type = "attachment"';
        $count = $db->get_row($sql)->counted;

        // init a progress bar
        $progress = \WP_CLI\Utils\make_progress_bar('Migrate Attachments', $count);

        // get the bucket name
        $bucket = preg_replace('#^.*/#', '', $siteDomain);

        // loop through the data
        for ($offset = 0; $offset < $count; $offset += $limit) {
            // get the data to search through
            $sql = 'SELECT * FROM '.$db->posts.' WHERE post_type = "attachment" LIMIT '.$limit.' OFFSET '.$offset;
            $data = $db->get_results($sql, ARRAY_A);

            // loop through the data looking for fields to replace
            foreach ($data as $record) {
                // get the attached value for this record
                $sql = 'SELECT * FROM '.$db->postmeta.' WHERE post_id = "'.$record['ID'].'" AND '.
                    'meta_key = "_wp_attached_file"';
                $attached = $db->get_results($sql, ARRAY_A);

                // get the s3 post meta data for this record
                $sql = 'SELECT * FROM '.$db->postmeta.' WHERE post_id = "'.$record['ID'].'" AND '.
                       'meta_key = "amazonS3_info"';
                $meta = $db->get_results($sql, ARRAY_A);

                // if no s3 data exists already then generate
                if (sizeof($meta) === 0 && sizeof($attached) === 1) {
                    // build the new s3 meta data
                    $url = 'wp-content/uploads/'.($db->blogid != 1 ? 'sites/'.$db->blogid.'/' : '').$attached[0]['meta_value'];
                    $metaData = array('bucket' => $bucket, 'key' => $url);

                    // save the meta data
                    if (!add_post_meta($record['ID'], 'amazonS3_info', $metaData)) {
                        WP_CLI::warning('Image migration failed on "'.$record['ID'].'"');
                    }
                }

                // update the progress var
                $progress->tick();
            }
        }
        $progress->finish();
    }

    /**
     * Migrates the images in the post table
     *
     * @param wpdb $db The database object
     * @param int $limit The number of records to return each slice
     * @param string $siteDomain The domain to replace the images' domain with
     */
    private function MigratePosts(wpdb $db, $limit, $siteDomain) {
        // get a count of posts
        $count = $this->GetCount($db, $db->posts);

        // init a progress bar
        $progress = \WP_CLI\Utils\make_progress_bar('Migrate Posts', $count);

        // loop through the data
        for ($offset = 0; $offset < $count; $offset += $limit) {
            // get the data to search through
            $data = $this->GetDataSlice($db, $db->posts, $limit, $offset);

            // loop through the data looking for fields to replace
            foreach ($data as $record) {
                $record['guid'] = $this->ReplaceUrl($record['guid'], $siteDomain);
                $record['post_content'] = $this->ReplaceUrl($record['post_content'], $siteDomain);

                // save the post data
                wp_update_post($record);

                // update the progress var
                $progress->tick();
            }
        }
        $progress->finish();
    }

    /**
     * Migrates the images in the post meta table
     *
     * @param wpdb $db The database object
     * @param int $limit The number of records to return each slice
     * @param string $siteDomain The domain to replace the images' domain with
     * @param array $ignoreKeys An array of keys to ignore converting
     */
    private function MigratePostMeta(wpdb $db, $limit, $siteDomain, $ignoreKeys = array()) {
        // get a count of posts
        $count = $this->GetCount($db, $db->postmeta);

        // init a progress bar
        $progress = \WP_CLI\Utils\make_progress_bar('Migrate Post Meta', $count);

        // loop through the data
        for ($offset = 0; $offset < $count; $offset += $limit) {
            // get the data to search through
            $data = $this->GetDataSlice($db, $db->postmeta, $limit, $offset);

            // loop through the data looking for fields to replace
            foreach ($data as $record) {
                // ignore certain keys as they don't need converting
                if (in_array($record['meta_key'], $ignoreKeys)) {
                    continue;
                }

                // unserialise the meta values
                $meta = unserialize($record['meta_value']);

                // loop through the meta values and update them
                if (is_array($meta)) {
                    foreach ($meta as $key => $value) {
                        $meta[$key] = $this->ReplaceUrl($value, $siteDomain);
                    }
                } else {
                    $meta = $this->ReplaceUrl($record['meta_value'], $siteDomain);
                }

                // save the post meta
                update_post_meta($record['post_id'], $record['meta_key'], $meta);

                // update the progress var
                $progress->tick();
            }
        }
        $progress->finish();
    }

    /**
     * Migrates the images in the options table
     *
     * @param wpdb $db The database object
     * @param int $limit The number of records to return each slice
     * @param string $siteDomain The domain to replace the images' domain with
     */
    private function MigrateOptions(wpdb $db, $limit, $siteDomain) {
        // get a count of posts
        $count = $this->GetCount($db, $db->options);

        // init a progress bar
        $progress = \WP_CLI\Utils\make_progress_bar('Migrate Options', $count);

        // loop through the data
        for ($offset = 0; $offset < $count; $offset += $limit) {
            // get the data to search through
            $data = $this->GetDataSlice($db, $db->options, $limit, $offset);

            // loop through the data looking for fields to replace
            foreach ($data as $record) {
                // unserialise the meta values
                $option = unserialize($record['option_value']);

                // loop through the meta values and update them
                if (is_array($option)) {
                    foreach ($option as $key => $value) {
                        if (is_string($value)) {
                            $option[$key] = $this->ReplaceUrl($value, $siteDomain);
                        }
                    }
                } else {
                    $option = $this->ReplaceUrl($record['option_value'], $siteDomain);
                }

                // save the post meta
                update_option($record['option_name'], $option);

                // update the progress var
                $progress->tick();
            }
        }
        $progress->finish();
    }

    /**
     * Finds images in text and replaces them with an S3 domain. Note, this
     * will replace any image, even those linked to from another site. As you
     * shouldn't ever be hotlinking then it's not really an issue, but worth
     * noting
     *
     * @param string $text The text to replace the images within
     * @param string $siteDomain The domain to replace the images' with
     * @return string The text with updated images
     */
    private function ReplaceUrl($text, $siteDomain) {
        // capture any image that matches a wp uploaded image url
        if (!is_array($text) && preg_match_all('#(https?://)([^/]+)(/wp-content/uploads/(.+)\.(png|gif|jpg|jpeg))#U', $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {

            // replace each image url in reverse order. This is because we're
            // using strpos, so in reverse order these will not change
            $matches = array_reverse($matches);
            foreach ($matches as $match) {
                // get the space to replace
                $startPos = $match[0][1];
                $length = strlen($match[0][0]);
                $url = $match[1][0].$siteDomain.$match[3][0];

                // rebuild the text with the new image
                $text = substr($text, 0, $startPos).$url.substr($text, $startPos + $length);
            }
        }

        return $text;
    }

    /**
     * Gets a slice of data as a list of objects. Used so we can easily loop
     * through all posts, post_meta, options, etc
     *
     * @param wpdb $db The database object
     * @param string $table The name of the table to get the data from
     * @param int $limit The number of records to return
     * @param int $offset Where to start counting the limit from
     * @return mixed[] An array of records
     */
    private function GetDataSlice(wpdb $db, $table, $limit, $offset) {
        // get a slice of the data
        $sql = 'SELECT * FROM '.$table.' LIMIT '.$limit.' OFFSET '.$offset;
        $data = $db->get_results($sql, ARRAY_A);

        return $data;
    }

    /**
     * Gets a count of the number of rows in the specified table
     *
     * @param wpdb $db The database object
     * @param string $table The name of the table to count
     * @return int The size of the table
     */
    private function GetCount(wpdb $db, $table) {
        // get a count of all data
        $sql = 'SELECT COUNT(*) AS counted FROM '.$table;
        $data = $db->get_row($sql);

        return isset($data->counted) ? $data->counted : 0;
    }

    /**
     * Wraps the WP DB object so that it's no longer a dirty, dirty global
     *
     * @return wpdb The WP DB object
     */
    private function GetDBObject() {
        global $wpdb;
        return $wpdb;
    }
}

// add the command to WP CLI
WP_CLI::add_command('s3', 'S3_Migrator_Command');



