<?php
/*
Plugin Name: Advanced Galleria
Plugin URI: http://lucanos.com
Description: Displays a beautiful Galleria slideshow in place of the built-in WordPress image grid. Overrides the default functionality of the [gallery] shortcode.
Version: 2.0.0.a.1
Author: Luke Stevenson
Author URI: http://lucanos.com/
License: The MIT License
*/

/**
 * The AdvancedGalleria WordPress Plugin class
 */
class AdvancedGalleria {

  protected $url;
  protected $theme;
  protected $version = '2.0.0.a.1';
  protected $galleriaVersion = '1.3.1';
  protected $default_options = array(
    'advanced_galleria_theme' => 'advanced-classic-light' ,
    'advanced_galleria_thumb' => 'thumbnail' ,
    'advanced_galleria_large' => 'large'
  );

  /**
   * Constructor
   *
   * @param string $pluginUrl The full URL to this plugin's directory.
   */
  public function __construct( $pluginUrl ){
    $this->url   = $pluginUrl;
    $this->theme = get_option( 'advanced_galleria_theme' , $this->default_options['advanced_galleria_theme'] );
    $this->initialize();
  }

  /**
   * Initializes this plugin
   */
  public function initialize(){

    // replace the default [gallery] shortcode functionality
    add_shortcode( 'gallery', array( &$this , 'galleryShortcode' ) );

    // determine the theme and version for the files to load
    $theme_js    = sprintf( '%s/galleria/themes/%s/galleria.%s.min.js' ,  $this->url , $this->theme , $this->theme );
    $theme_css   = sprintf( '%s/galleria/themes/%s/galleria.%s.min.css' , $this->url , $this->theme , $this->theme );
    $galleria_js = sprintf( '%s/galleria/galleria-%s.min.js' ,            $this->url , $this->galleriaVersion );

    // add required scripts and styles to head
    wp_enqueue_script( 'jquery' );
    wp_enqueue_script( 'advanced-galleria' ,       $galleria_js , 'jquery' ,            $this->galleriaVersion );
    wp_enqueue_script( 'advanced-galleria-theme' , $theme_js ,    'advanced-galleria' , $this->version );
    wp_enqueue_style(  'advanced-galleria-style' , $theme_css ,   array() ,             $this->version );

   // admin options page
    add_action( 'admin_menu' , array( &$this , 'addOptionsPage' ) );

  }

  /**
   * Displays a Galleria slideshow using images attached to the specified post/page.
   * Overrides the default functionality of the [gallery] Shortcode.
   *
   * @param array $attr Attributes of the shortcode.
   * @return string HTML content to display gallery.
   */
  public function galleryShortcode( $attr ){

    global $post, $content_width;

   // global content width set for this theme? (see theme functions.php)
    if( !isset( $content_width ) )
      $content_width = 'auto';

   // make sure each slideshow that is rendered for the current request has a unique ID
    static $instance = 0;
    $instance++;

   // yield to other plugins/themes attempting to override the default gallery shortcode
    $output = apply_filters( 'post_gallery' , '' , $attr );
    if( $output!='' )
      return $output;

   // We're trusting author input, so let's at least make sure it looks like a valid orderby statement
    if( isset( $attr['orderby'] ) ){
      $attr['orderby'] = sanitize_sql_orderby( $attr['orderby'] );
      if( !$attr['orderby'] )
        unset( $attr['orderby'] );
    }

   // 3:2 display ratio of the stage, account for 60px thumbnail strip at the bottom
    $width  = 'auto';
    $height = '0.76'; // a fraction of the width

   // defaults if not set
    $autoplay = false;
    $captions = 'on_expand'; // off, on_hidden, on_expand
    $hideControls = false;

   // extract the shortcode attributes into the current variable space
    extract(shortcode_atts(array(
     // standard WP [gallery] shortcode options
      'order'        => 'ASC' ,
      'orderby'      => 'menu_order ID' ,
      'id'           => $post->ID ,
      'itemtag'      => 'dl' ,
      'icontag'      => 'dt' ,
      'captiontag'   => 'dd' ,
      'columns'      => 3 ,
      'size'         => '' ,
      'include'      => '' ,
      'exclude'      => '' ,
      'ids'          => '' ,
     // galleria options
      'width'        => $width ,
      'height'       => $height ,
      'autoplay'     => $autoplay ,
      'captions'     => $captions ,
      'hideControls' => $hideControls ,
    ), $attr));

   // the id of the current post, or a different post if specified in the shortcode
    $id = intval( $id );

    // random MySQL ordering doesn't need two attributes
    if( $order=='RAND' )
      $orderby = 'none';

   // use the given IDs of images
    if( !empty( $ids ) )
      $include = $ids;

   // fetch the images
    $args = array(
      'post_status'    => 'inherit' ,
      'post_type'      => 'attachment' ,
      'post_mime_type' => 'image' ,
      'order'          => $order ,
      'orderby'        => $orderby ,
    );
    if( !empty( $include ) ){
     // include only the given image IDs 
      $include = preg_replace( '/[^0-9,]+/' , '' , $include );
      $args['include'] = $include;
      $_attachments = get_posts($args);
      $attachments = array();
      foreach( $_attachments as $k => $v )
        $attachments[$v->ID] = $_attachments[$k];
      if( !empty( $ids ) ){
        $sortedAttachments = array();
        $ids = preg_replace( '/[^0-9,]+/' , '' , $ids );
        $idsArray = explode( ',' , $ids );
        foreach( $idsArray as $aid ){
          if( array_key_exists( $aid , $attachments ) )
            $sortedAttachments[$aid] = $attachments[$aid];
        }
        $attachments = $sortedAttachments;
      }
    }else{
     // default: all images attached to this post/page
      $args['post_parent'] = $id;
      if( !empty( $exclude ) ){
       // exclude certain image IDs
        $args['exclude'] = preg_replace( '/[^0-9,]+/' , '' , $exclude );
      }
      $attachments = get_children($args);
    }

    // output nothing if we didn't find any images
    if( count( $attachments )==1 ){
      $ak = array_keys( $attachments );
      if( $ak[0] == get_post_thumbnail_id( $post->ID ) )
        $attachments = array();
    }
    #if( empty( $attachments ) )
    #  return '';

    // output the individual images when displaying as a news feed
    if( is_feed() ){
      $output = "\n";
      foreach( $attachments as $attachmentId => $attachment ){
        list( $src , $w , $h ) = wp_get_attachment_image_src( $attachmentId , 'medium' );
        $output .= '<img src="'.$src.'" width="'.$w.'" height="'.$h.'">' . "\n";
      }
      return $output;
    }

    /***************/
    // advanced-galleria
    /***************/

   // make an array of images with the proper data for Galleria
    $images = array();
    $image_size_thumb = get_option( 'advanced_galleria_thumb' , $this->default_options['advanced_galleria_thumb'] );
    $image_size_large = get_option( 'advanced_galleria_large' , $this->default_options['advanced_galleria_large'] );
    foreach( $attachments as $attachmentId => $attachment ){
      $thumb = wp_get_attachment_image_src( $attachmentId , $image_size_thumb );
      $large = wp_get_attachment_image_src( $attachmentId , $image_size_large );
      $images[] = array(
        'url'         => $large[0] ,
        'big'         => $large[0] ,
        'thumb'       => $thumb[0] ,
        'title'       => $attachment->post_title ,
        'description' => wptexturize( $attachment->post_excerpt ) ,
        'type'        => 'image' ,
      );
    }
    
    $images = apply_filters( 'advanced_galleria_images' , $images );

    if( !is_array( $images ) || !count( $images ) )
      return '';

    // The Galleria options
    $options = array(
      'width'             => ( is_numeric( $width ) ? ( int ) $width  : ( string ) $width ) ,
      'height'            => ( is_int( $height )    ? ( int ) $height : ( float )  $height ) ,
      'autoplay'          => ( boolean ) $autoplay ,
      'transition'        => 'slide' ,
      'initialTransition' => 'fade' ,
      'transitionSpeed'   => ( int ) 0.5 * 1000 , // milliseconds
      '_delayTime'        => ( int ) 4 * 1000 , // milliseconds
      '_hideControls'     => $hideControls ,
      '_thumbnailMode'    => 'grid' ,
      '_captionMode'      => $captions ,
    );

    $options = apply_filters( 'advanced_galleria_options' , $options );

   // Encode as JSON
    $options = json_encode( $options );

   // unique ID for this slideshow
    $domId = 'advanced_galleria_slideshow_'.$instance;

   // the DOM is built in JavaScript so we just need a placeholder div
    $output .= '<div id="'.$domId.'" class="gallery gallery-size-'.$image_size_thumb.' advanced-galleria-slideshow">';
    foreach( $images as $v ){
      switch( $v['type'] ){
        case 'youtube' :
          if( isset( $v['thumb'] ) ){
            $output .= '<a href="'.$v['url'].'" title="'.$v['title'].'" class="gallery-item"><img src="'.$v['thumb'].'" alt="'.$v['description'].'" /></a>';
          }else{
            $output .= '<a href="'.$v['url'].'" title="'.$v['title'].'" class="gallery-item"><span class="video">'.$v['title'].( $v['description'] ? ' - '.$v['description'] : '' ).'</span></a>';
          }
          break;
        case 'image' :
        default :
          $output .= '<a href="'.$v['url'].'" title="'.$v['title'].'" class="gallery-item"><img src="'.$v['thumb'].'" alt="'.$v['description'].'" data-title="'.$v['title'].'" data-description="'.$v['description'].'" /></a>';
      }
    }
    $output .= '</div>';

    // galleria JavaScript output
    // NOTE: WordPress disables the use of the dollar-sign function ($) for compatibility
    $output .= '<script type="text/javascript">jQuery(document).ready(function(){ jQuery("#' . $domId . '").galleria(' . $options . '); });</script>';

    return $output;
  }

  /**
   * Adds the callback for the admin options page.
   * @return void
   */
  public function addOptionsPage(){
    add_options_page('Galleria', 'Galleria', 'manage_options', 'galleria', array(&$this, 'showOptionsPage'));
  }

  /**
   * Displays the admin settings page.
   * If a POST request, saves the submitted plugin options.
   * @return void
   */
  public function showOptionsPage(){
    global $_wp_additional_image_sizes;

    if (!current_user_can('manage_options'))  {
      wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    $options_updated = false;
    foreach( $this->default_options as $k => $v ){
      if( isset( $_POST[$k] ) ){
        update_option( $k , $_POST[$k] );
        $options_updated = true;
      }else{
        update_option( $k , $v );
      }
    }
    if( $options_updated )
      echo '<div id="message" class="updated fade"><p><strong>Options saved.</strong></p></div>';

   // get the current option value
    $theme = get_option( 'advanced_galleria_theme' , $this->default_options['advanced_galleria_theme'] );
    $thumb = get_option( 'advanced_galleria_thumb' , $this->default_options['advanced_galleria_thumb'] );
    $large = get_option( 'advanced_galleria_large' , $this->default_options['advanced_galleria_large'] );

    $availableThemes = array(
      'advanced-classic-light' => 'Classic Light (with fullscreen button)',
      'advanced-classic' => 'Classic Dark (with fullscreen button)',
      'classic' => 'Classic Dark (no fullscreen)'
    );

?>
    <div class="wrap">
      <h2>Galleria Settings</h2>
      <form method="post" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
        <table class="form-table">
          <tbody>
            <tr valign="top">
              <th scope="row">
                <label for="advanced_galleria_theme">Theme</label>
              </th>
              <td>
                <select id="advanced_galleria_theme" name="advanced_galleria_theme">
<?php foreach( $availableThemes as $k => $v ){ ?>
                  <option value="<?php echo $k; ?>"<?php echo ( $theme==$k ? ' selected="selected"' : '' ); ?>><?php echo $v; ?></option>
<?php } ?>
                </select>
              </td>
            </tr>
            <tr>
              <th scope="row">
                <label for="advanced_galleria_thumb">Thumbnail Size</label>
              </th>
              <td>
                <input type="text" id="advanced_galleria_thumb" name="advanced_galleria_thumb" value="<?php echo $thumb; ?>" />
              </td>
            </tr>
            <tr>
              <th scope="row">
                <label for="advanced_galleria_large">Large Size</label>
              </th>
              <td>
                <input type="text" id="advanced_galleria_large" name="advanced_galleria_large" value="<?php echo $large; ?>" />
              </td>
            </tr>
          </tbody>
        </table>
        <p class="submit"><input type="submit" value="Save Changes" class="button button-primary"></p>
      </form>
    </div>
<?php

  }

}

$advanced_galleria = new AdvancedGalleria( plugins_url( basename( dirname( __FILE__ ) ) ) );
