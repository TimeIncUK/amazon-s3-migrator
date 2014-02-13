Fixes for external images

Currently any external images aren't saving their sizes, and aren't being shown in the customiser.

There were some lines that removed the protocol from the url, so these have currently been commented out, to be removed at a later date.

It now checks to see if the image size can be obtained and gracefully fails from that.

A simple cache has been added to stop the size of the images being retrieved multiple times in 1 PHP call, and the code that checks in the first place has been tightened up.

class cftp_customiser {

+    /**
+     * An assoc. array to store the images sizes of posted images. This is
+     * useful because when the page refreshes after an image has been changed
+     * it requests the size of the same image multiple times, and if these
+     * images are external the load times were being measured in minutes
+     *
+     * @var array
+     */
+    private $cached_image_sizes = array();
+
/**
* Add theme customiser controls, manage cache busting and less variables
*
@@ -1291,7 +1301,7 @@ class cftp_customiser {
function save() {

        $size = $this->image_size( get_theme_mod('logo', '') );
-               $sizesmall = $this->image_size( get_theme_mod('logosmall', '') );
+               $sizesmall = $this->image_size( get_theme_mod('logosmall', '') );


        $size = $this->image_size( get_theme_mod('logo', '') );
-               $sizesmall = $this->image_size( get_theme_mod('logosmall', '') );
+               $sizesmall = $this->image_size( get_theme_mod('logosmall', '') );

        if ( ! empty( $size ) )
                set_theme_mod( 'logosize', $size );
@@ -1492,8 +1502,13 @@ class cftp_customiser {

        $new = json_decode( wp_unslash( $_POST['customized'] ), true );

-               // check data from customiser refresh
-               // print_r($new);
+        // make sure the correct logo sizes are returned
+        if ($option['logo'] != $new['logo']) {
+            $option['logosize'] = $this->image_size( $new['logo'] );
+        }
+        if ($option['logosmall'] != $new['logosmall']) {
+            $option['logosmallsize'] = $this->image_size( $new['logosmall'] );
+        }

        // merge all other keys
        foreach ( $option as $key => $o ) {
@@ -1505,10 +1520,6 @@ class cftp_customiser {
                }
        }

-               // make sure the correct logo sizes are returned
-               $option['logosize'] = $this->image_size( $new['logo'] );
-               $option['logosmallsize'] = $this->image_size( $new['logosmall'] );
-
        return $option;
}

@@ -1676,25 +1687,41 @@ class cftp_customiser {

        $size_return = array();

-               if ($image == '') return $size_return;
+               if ($image == '') {
        $size_return = array();

-               if ($image == '') return $size_return;
+               if ($image == '') {
+            return $size_return;
+        }
+
+        // return the cached size
+        if (isset($this->cached_image_sizes[$image])) {
+            return $this->cached_image_sizes[$image];
+        }

        // convert the URL to a path
        $upload = wp_upload_dir();
-               $imageurl = str_replace(array('http://', 'https://'), '', $image);
-               $uploadurl = str_replace(array('http://', 'https://'), '', $upload['baseurl']);
-               $image = str_replace($uploadurl, $upload['basedir'], $imageurl);
-
-               // bail if the file no longer exists
-               if ( ! file_exists( $image ) ) return $size_return;
-
-               // determine image size
-               $size = getimagesize( $image );
+               // $imageurl = str_replace(array('http://', 'https://'), '', $image);
+               // $uploadurl = str_replace(array('http://', 'https://'), '', $upload['baseurl']);
+               $image = str_replace($upload['baseurl'], $upload['basedir'], $image);
+
+        // determine image size
+        try {
+            $size = getimagesize($image);
+        } catch (Exception $e) {
+            $size = null;
+        }
+
+               // bail if the file cannot be retrieved
+               if ($size === null) {
+            return $size_return;
+        }

        if ( ! empty( $size ) ) {
                $size_return['width'] = $size[0];
                $size_return['height'] = $size[1];
        }

+        // store the found image size
+        $this->cached_image_sizes[$image] = $size_return;
+
        return $size_return;
}
}
