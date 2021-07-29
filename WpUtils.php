<?php
/**
* WpUtils Class
*/
namespace Utils;

use Exception;
use WPSEO_Primary_Term;

/**
* A package of methods to speed up some basic tasks.
*
* Here you'll find methods to help with some common WP tasks.
* To avoid we rewrite over and over basics functions.
*
* @category Utils
* @package Utils
* @author Victor Larodiel <me@larods.com.br>
* @license GPLv2 https://www.gnu.org/licenses/gpl-2.0.en.html
* @version 1.0.0
* @link https://github.com/larodiel/wputils
*/

class WpUtils {
  /** @internal use class with static object */
  private static $instance = null;

  /** @internal used to define which tag is allowed on excerpt */
  private $allowedExcerptTags = '<a>';

  /**
  * $excerptSize
  *
  * Set the size of excerpt on posts by words
  * @var integer
  */
  public $excerptSize = 55;

  /** @internal A list of plugins that will not be visible on plugins.php */
  private $pluginsToHide = [];

  /** @internal Widgets to be removed from widgets.php */
  private $widgetsToRemove = [];

  /** @internal Class Version */
  const VERSION = '1.0.0';

  /**
  * Set the initial filters and excerpt length
  * @uses excerptLength()
  * @param int $excerptSize int value to define the excerpt size.
  */
  public function __construct($excerptSize = 55) {
    $this->excerptLength($excerptSize);
    add_filter('body_class', [$this, 'filterBodyClass']);
    add_filter('excerpt_length', [$this, 'excerptLength'], 999);
  }

  /**
  * Singleton
  *
  * @return self
  */
  public static function utils() {
    if (null === self::$instance) {
      self::$instance = new self();
    }

    return self::$instance;
  }

  /**
  *  Check if postID is valid.
  *
  * @param int $postID The post ID must be integer.
  * @return int $postID|false
  */
  public function checkPostID($postID) {
    if (!is_int($postID) && $postID <= 0) {
      return 0;
    }

    return $postID;
  }

  /**
  * @internal
  *
  * add new classes on `<body>`
  *
  * @param array $classes return the array of classes to be added on `<body>`
  * @return array $classes
  */
  public function filterBodyClass($classes) {
    $device         = 'is-desktop';
    $custom_classes = ['wputils', 'wputils-'.self::VERSION];

    if (wp_is_mobile()) {
      $device = 'is-mobile';
    }

    $custom_classes[] = $device;

    //Add a class to the body tag in case the parameter 'testing' is true
    if (filter_input(INPUT_GET, 'testing')) {
      $custom_classes[] = 'testing-'.filter_input(INPUT_GET, 'testing');
      $custom_classes[] = 'testing';
    }

    //Add the basename if it's a single(post page) or a page
    if (is_single() || is_page() && !is_front_page()) {
      if (!in_array(basename(get_permalink()), $classes)) {
        $custom_classes[] = basename(get_permalink());
      }
    }

    $classes = array_merge($classes, $custom_classes);

    return $classes;
  }

  /**
   * Return a taxonomy including it children
   *
   * @param string $field type of that you want to use to get 'slug', 'name', 'id' or 'ID' (term_id), or 'term_taxonomy_id'
   * @param string|integer $val Search for this term value.
   * @param string $tax Taxonomy slug
   * @return array|false
   */
  public function getTaxonomy($field, $val, $taxonomy = '') {
    $term = get_term_by($field, $val, $taxonomy);

    if(is_wp_error($term) || empty($term)) return false;

    $term->children  = [];
    $term->is_parent = $term->parent === 0 ? true : false;
    $children        = get_term_children($term->term_id, $taxonomy);
    $term->link      = get_term_link($term, $taxonomy);

    if(!is_wp_error($children) && !empty($children)) {
      $children_count = count($children);

      for ($i = 0; $i < $children_count; $i++) {
        $child = get_term_by('ID', $children_count[$i], $taxonomy);
        $child->is_parent = false;
        $child->link = get_term_link($child, $taxonomy);
        $term->children[] = $child;
      }
    }

    return $term;
  }

  /**
  * Get the primary category set on YOAST Plugin
  *
  *
  * @param int $postID Post ID must be integer and greater than 0.
  * @param string $taxonomy
  * @return array|false
  */
  public function getPrimaryTaxTerm($postID, $taxonomy = 'category') {
    if(!$this->checkPostID($postID)) return false;

    $post_type  = get_post_type($postID);
    $taxonomies = get_object_taxonomies($post_type);

    if (!in_array($taxonomy, $taxonomies)) return false;

    $terms        = get_the_terms($postID, $taxonomy);
    $primary_term = array();

    if (!is_wp_error($terms) && empty($terms) === false) {
      $primary_term = [
        'ID'    => $terms[0]->term_id,
        'title' => $terms[0]->name,
        'slug'  => $terms[0]->slug,
        'url'   => get_term_link($terms[0]->term_id),
      ];

      if (class_exists('\WPSEO_Primary_Term')) {
        $wpseo_primary_term = new \WPSEO_Primary_Term($taxonomy, $postID);
        $wpseo_primary_term = $wpseo_primary_term->get_primary_term();
        $term               = get_term($wpseo_primary_term);

        if(is_wp_error($terms) || empty($terms)) return $primary_term;

        $primary_term = [
          'ID'    => $term->term_id,
          'title' => $term->name,
          'slug'  => $term->slug,
          'url'   => get_term_link($term->term_id),
        ];
      }
    }

    return $primary_term;
  }

  /**
  * Get parent category and return it object.
  *
  * @param int $catID Category ID must be integer and greater than 0.
  * @return object|false
  */
  public function getParentCat($catID) {
    $cat_obj = get_category($catID);

    if ($this->isCatParent($catID)) {
      return $cat_obj;
    }

    $parent_cat = get_category($cat_obj->parent);

    return $parent_cat;
  }

  /**
  * Get the category on category template/page
  *
  * @return object|WP_Error
  */
  public function getCurrentCat() {
    $current_cat = get_category(get_query_var('cat'));
    return $current_cat;
  }

  /**
  * Get children categories by parent ID
  *
  * @param int $parentID Parent ID of the category that you want to get children.
  * @return array|WP_Error
  */
  public function getChildrenCat($parentID) {
    $parent_cat = get_categories(['parent' => $parentID]);
    return $parent_cat;
  }

  /**
  * Check if current category is the parent.
  *
  * @param int $catID Category ID must be integer.
  * @return boolean
  */
  public function isCatParent($catID) {
    $cat = get_term($catID);
    if (!is_wp_error($cat)) {
      return $cat->parent == 0;
    }

    return false;
  }

  /**
  * Check if the page has child or not
  *
  *
  * @param int    $parentID   Parent ID must be integer.
  * @param string $postStatus post status must be any|publish|draft, default: 'publish'.
  * @return boolean
  */
  public function hasChildrenPages($parentID, $postStatus = 'publish') {
    $children = get_pages(array('child_of' => $parentID, 'post_status' => $postStatus));

    if (!is_wp_error($children) && !empty($children)) {
      return true;
    }

    return false;
  }

  /**
  * If it's the parent will retur it $postID otherwise will return the $postID of it parent
  *
  * @param int $postID The post ID must be integer.
  * @return int|false
  */
  public function getParentID($postID) {
    if(!$this->checkPostID($postID)) return 0;

    $parentID = wp_get_post_parent_id($postID);

    if ($parentID) return $parentID;

    return 0;
  }

  /**
  * Returns all children pages of the given ID
  *
  * @param int    $parentID   Parent ID must be integer and greater than 0.
  * @param string $postStatus post status must be any|publish|draft, default: 'publish'.
  * @return array|false
  */
  public function getPageChildren($parentID, $postStatus = 'publish') {
    if ($parentID > 0 && $this->hasChildrenPages($parentID)) {

      return get_children([
        'post_parent' => $parentID,
        'post_type'   => 'page',
        'numberposts' => -1,
        'post_status' => $postStatus,
        'orderby'     => 'menu_order',
        'order'       => 'ASC'
      ]);
    }

    return false;
  }

  /**
  * Encode the given email to avoid bots spam it.
  *
  * @param string $email Email string to be encoded.
  * @return string
  */
  public function encodeEmail($email) {
    $mail_length = strlen($email);
    for ($i = 0; $i < $mail_length; $i++) {
      $output .= '&#'.ord($email[$i]).';';
    }
    return $output;
  }

  /**
  * Check if the parameter 'testing' is given as query string.
  * It adds a class on ```<body>``` called *__testing__* to we deal with css things.
  *
  *
  * @param string $testing String to be used as param in the query string `testing`
  * @return boolean
  */
  public function isTesting($testing = '') {
    $testing_filter = filter_input(INPUT_GET, 'testing');

    if ($testing != '') {
      if ($testing_filter == $testing) {
        return true;
      }
    }

    return $testing_filter;
  }

  /**
  * Prepend *0* on single numbers.
  * 1 turns 01
  *
  *
  * @param int $num Int number to append `0` on it
  * @return string
  */
  public function prepend0($num) {
    return sprintf("%02d", $num);
  }

  /**
  * Catch the first image on a post.
  *
  *
  * @param int    $postID      Post ID must be integer and greater than 0.
  * @param string $class       Image class attribute
  * @param string $postContent Post content string ex: `$post->post_content`
  * @param bool   $returnUrl   Define if it will return just the URL or full img tag.
  * @return string
  */
  public function catchImage($postID, $class = '', $postContent = '', $returnUrl = null) {
    if(!$this->checkPostID($postID)) return '';

    $image   = '';

    if (empty($postContent)) {
      $postContent = get_post_field('post_content', $postID);;
    }

    preg_match_all('/<img.+src=[\'"]([^\'"]+)[\'"].*>/i', $postContent, $matches);

    if (is_array($matches) && isset($matches[1][0])) {
      $image = $matches[1][0];
    }

    if (empty($image)) {
      $image = "https://picsum.photos/1200/1200?random={$postID}";
    }

    if ($returnUrl) {
      return $image;
    }

    return "<img src='{$image}' alt='".esc_attr(wp_basename($image))."' class='{$class}' />";;
  }

  /**
  * Set the number of words on excerpt.
  *
  *
  * @param int $length Excerpt size.
  * @return int
  */
  public function excerptLength($length) {
    $this->excerptSize = $length;
    return $length;
  }

  /**
  * Set allowed tags on excerpt ( pepareted by comma )
  *
  *
  * @param string $tags Tags allowed on excerpt.
  * @return void
  */
  public function setExcerptTags($tags = '<a>') {
    $this->allowedExcerptTags = $tags;
  }

  /**
  * Get the allowed tags on excerpt.
  *
  * @return string
  */
  public function getExcerptTags() {
    return $this->allowedExcerptTags;
  }

  /**
  * Turns a full text into an excerpt.
  *
  * @param string $text           Text to be cutted.
  * @param bool   $finishSentence Allows to the paragraph be finished even after reach the limit.
  * @param string $excerptEnd     Excerpt suffix, default: '...'
  * @return string
  */
  public function excerpt($text, $finishSentence = null, $excerptEnd = '&hellip;') {
    //Word length of the excerpt. This is exact or NOT depending on your '$finishSentence' variable.
    $tokens = array();
    $out    = '';
    $word   = 0;
    $regex  = '/(<[^>]+>|[^<>\s]+)\s*/u';
    $regex1 = '/[\?\.\!]\s*$/uS';

    preg_match_all($regex, $text, $tokens);

    foreach ($tokens[0] as $t) {
      if ($word >= $this->excerptSize && !$finishSentence) {
        break;
      }

      if ($t[0] != '<') {
        if ($word >= $this->excerptSize && $finishSentence && preg_match($regex1, $t) == 1) {
          $out .= trim($t);
          break;
        }
        $word++;
      }
      $out .= $t;
    }

    $out = preg_replace("/\[caption .+?\[\/caption\]|\< *[img][^\>]*[.]*\>/i", "", $out);
    $out = preg_replace("/\[[\/]?(vc_|et)[^\]]*\]/", "", $out);
    $out = strip_shortcodes($out);
    $out = strip_tags($out, $this->allowedExcerptTags);
    $out = force_balance_tags($out);
    $out = trim($out);

    if (str_word_count($out) > $this->excerptSize) {
      $out .= $excerptEnd;
    }

    return $out;
  }

  /**
  * Set a excerpt to a post.
  * It verify if post has an excerpt set, if not, the main text will be cutted.
  *
  * @return string
  */
  public function getPostExcerpt() {
    $post_excerpt = $this->excerpt(get_the_content());

    if (has_excerpt()) {
      $post_excerpt = get_the_excerpt();
    }

    return $post_excerpt;
  }

  /**
  * Get first RewardStyle on provided content.
  *
  * @param string $content post content
  * @return string|false
  */
  public function getRewardstyleShortcode($content) {
    if (preg_match("/\[show_shopthepost_widget id=[\',\"]+[0-9]+[\',\"]\]/im", $content, $matches)) {
      foreach ($matches as $shortcode) {
        $code = $shortcode;
      }
      return $code;
    } elseif (preg_match("/data-widget-id=([\',\"]+[0-9]+[\',\"])/mi", $content, $match, PREG_OFFSET_CAPTURE)) {
      return '[show_shopthepost_widget id='.$match[1][0].']';
    }

    return false;
  }

  /**
  * Get first Shopbop on provided content.
  *
  * @param string $content post content
  * @return string|false
  */
  public function getShopbopCode($content) {
    if (preg_match("/\[show_lookbook_widget id=[\',\"]+[0-9]+[\',\"]\]/im", $content, $matches)) {
      foreach ($matches as $shortcode) {
        $code = $shortcode;
      }
      return $code;
    }
    return false;
  }

  /**
  * Get ShopStyle code on provided content.
  *
  * @param string $content post content
  * @return string|false
  */
  public function getShopstyleCode($content) {
    $rExpression = "/\<div class=\"shopsense-widget\"([\\s\\S]*?)(.*|[\n\r])+((.*)|[\r\n])+(.*)[\r\n]+(.*)\<\/div\>/";
    $altRegEx    = "/\<div data-sc-widget-id=.*.<\/div\>/";

    if (preg_match($rExpression, $content, $matches)) {
      return $matches[0];
    } elseif (preg_match($altRegEx, $content, $matches)) {
      return $matches[0];
    }
    return false;
  }

  /**
  * Get first shop code found on provided content.
  *
  * It will check for ACF (stp_shop), RewardStyle, ShopStyle, Shopbop and will return the first shop found.
  *
  * @param string $content $content
  * @return string|false
  */
  public function getPostShop($content = null) {
    $shop = null;
    global $post;

    if (empty($content) || is_null($content)) {
      $content = $post->post_content;
    }

    if (function_exists('get_field') && get_field('stp_shop', $post->ID)) {
      $shop = get_field('stp_shop', $post->ID);
    } elseif ($this->getRewardstyleShortcode($content) !== false) {
      $shop = $this->getRewardstyleShortcode($content);
    } elseif ($this->getShopstyleCode($content) !== false) {
      $shop = $this->getShopstyleCode($content);
    } elseif ($this->getShopbopCode($content) !== false) {
      $shop = $this->getShopbopCode($content);
    }

    $shop = do_shortcode($shop);

    return $shop;
  }

  /**
  * Set a list of plugins to be hide on plugins.php
  *
  * @param array $plugins Plugins path array `plugin_folder/plugin_file.php`.
  * @return void
  */
  public function setPluginsToHide($plugins = []) {
    $this->pluginsToHide = $plugins;
    add_action('pre_current_active_plugins', [$this, 'actionHidePluginsFromList']);
  }

  /**
  * @internal
  * Unset the plugins provided on setPluginsToHide()
  *
  * @return void
  */
  public function actionHidePluginsFromList() {
    if (!is_array($plugins)) {
      throw new Exception('The param need to be an array', 1);
    }

    $plugins = $this->pluginsToHide;

    //plugin_folder/plugin_file.php
    global $wp_list_table;
    $myplugins = $wp_list_table->items;
    foreach ($myplugins as $key => $val) {
      if (in_array($key, $plugins)) {
        $val = $val;
        unset($wp_list_table->items[$key]);
      }
    }
  }

  /**
  * @internal
  * Remove widgets from the list on widget.php
  *
  * @return void
  */
  public function actionUnregisterWidget() {
    foreach ($this->widgetsToRemove as $widget) {
      unregister_widget($widget);
    }
  }

  /**
  * Set widgets to be remove from the list on widgets.php.
  * The array must contain the class of widget that will be remove.
  * ``` php
  *  $wputils->setWidgetsToRemove(['WP_Widget_Calendar', 'WP_Widget_RSS', 'WP_Nav_Menu_Widget', 'WP_Widget_Search']);
  * ```
  *
  * @param array $widgets Array of widgets class to be removed.
  * @return void
  */
  public function setWidgetsToRemove($widgets = []) {
    if (!is_array($widgets)) {
      throw new Exception('The param need to be an array', 1);
    }

    $this->widgetsToRemove = $widgets;

    add_action('widgets_init', [$this, 'actionUnregisterWidget'], 15);
  }

  /**
  * Check if the post thumbnail is set, if not returns the first image found on post.
  *
  * @param int    $postID    Post ID must be integer and greater than 0.
  * @param string $size      Image size, default: 'full'
  * @param string $returnUrl Return image URL or full img tag.
  * @return string
  */
  public function getPostFeatured($postID = 0, $size = 'full', $returnUrl = null) {
    $postID    = $this->checkPostID($postID);
    $thumbnail = $this->catchImage($postID, 'post-featured-image', get_post_field('post_content', $postID), $returnUrl);

    if (has_post_thumbnail($postID)) {
      $thumbnail = get_the_post_thumbnail($postID, $size);

      if ($returnUrl) {
        $thumbnail = get_the_post_thumbnail_url($postID, $size);
      }
    }

    return $thumbnail;
  }

  /**
  * Get the *time ago* string of a provided date.
  *
  * @param string $postDate Valid php date
  * @param string $label    label to be insert after the time, default: 'ago'
  * @return string
  */
  public function timeAgo($postDate, $label = 'ago') {
    $time       = strtotime($postDate);
    $human_diff = human_time_diff($time, current_time('timestamp')).' '.__($label);

    return $human_diff;
  }

  /**
  * Get videos from YouTube feed and return an array.
  *
  *  The array contains ID, link, title, description, thumbnail, channelURL
  *
  * @param string  $cid   YouTube channel ID
  * @param integer $limit Videos to show
  * @return array
  */
  public function getYTvideos($cid, $limit = 3) {
    if (empty($cid)) {
      return false;
    }

    $temp = file_get_contents("https://www.youtube.com/feeds/videos.xml?channel_id={$cid}");
    $xml  = simplexml_load_string($temp);

    if (empty($xml)) {
      return false;
    }

    $limit      = $limit > count($xml->entry) ? count($xml->entry) : $limit;
    $channelURL = $xml->author->uri.'?sub_confirmation=1';
    $videosData = [];

    for ($x = 0; $x < $limit; $x++) {
      $entry       = $xml->entry[$x];
      $video       = $entry->children('http://search.yahoo.com/mrss/')->group;
      $description = $video->description;
      $vID         = $entry->children('yt', true)->videoId;
      $title       = $entry->title;
      $link        = $entry->link->attributes()->href.'&utm_source=blog&utm_medium=youtube&utm_campaign='.sanitize_title($entry->title);
      $imageServer = rand(1, 4);
      $thumbnail   = "https://i{$imageServer}.ytimg.com/vi/{$vID}/maxresdefault.jpg";
      $imgResponse = get_headers($thumbnail);

      if (strpos($imgResponse[0], '404')) {
        $thumbnail = $video->thumbnail->attributes()->url;
      }

      $itemData = [
        'ID'          => $vID,
        'link'        => $link ,
        'title'       => $title,
        'description' => $description,
        'thumbnail'   => $thumbnail,
        'channelURL'  => $channelURL
      ];

      array_push($videosData, $itemData);
    }
    return $videosData;
  }

  /**
  * Check if the given size exist
  *
  * @param string $size Image size as string
  * @return bool
  */
  function imageSizeExists($size) {
    global $_wp_additional_image_sizes;

    if(is_array($_wp_additional_image_sizes)) {
      return array_key_exists($size, $_wp_additional_image_sizes);
    }
    return false;
  }

  /**
  * @internal Return the html script of the buttons actions
  */
  protected function taxImageFieldButtonsJs() {
    return '
    <script>
    jQuery(document).ready( function($) {
      $("#cd_tax_media_add").on("click", function(event) {
        event.preventDefault();

        var image_field_id = $(this).data("image_field");

        wp.media.editor.send.attachment = (props, attachment) => {
          $(image_field_id).val(attachment.id);
          $("#cd-tax-image-wrapper").html(`
          <img class="cd-tax-image" loading="lazy" src="${attachment.url}" style="margin:0;padding:0;float:none;max-width:100%;" />
          `);
        };
        wp.media.editor.open();
      });

      $("#cd_tax_media_remove").on("click", function(event) {
        event.preventDefault();
        var image_field_id = $(this).data("image_field");
        $(image_field_id).val("");
        $("#cd-tax-image-wrapper img").remove();
      });

      $(document).ajaxComplete(function(event, xhr, settings) {
        var queryStringArr = settings.data.split("&");
        if( $.inArray("action=add-tag", queryStringArr) !== -1 ){
          var xml = xhr.responseXML;
          var _response = $(xml).find("term_id").text();
          if(_response != ""){
            // Clear the thumb image
            $("#cd-tax-image-wrapper").html("");
          }
        }
      });
    });
    </script>
    ';
  }

  /**
  * @internal Return the html of the buttons to image taxonomy field.
  */
  protected function taxImageFieldButtons($field_id = '') {
    $btn_add    = __(' Add Image', 'larodiel');
    $btn_remove = __(' Remove Image', 'larodiel');

    return "
    <p>
    <button data-image_field='#{$field_id}' class='button button-primary' id='cd_tax_media_add' name='cd_tax_media_add'>{$btn_add}</button>
    <input data-image_field='#{$field_id}' type='button' class='button button-secondary' id='cd_tax_media_remove' name='cd_tax_media_remove' value='{$btn_remove}' />
    </p>
    {$this->taxImageFieldButtonsJs()}
    ";
  }

  /**
  * @internal Function to add the image field to the add taxonomy screen using the hook {$taxonomy}_add_form_fields
  */
  public function taxImageFieldAction($tax_slug) {
    $label    = __(' Image', 'larodiel');
    $field_id = "tax__{$tax_slug}-image-id";
    echo "
    <hr>
    <div class='form-field term-group' style='margin-bottom: 40px;'>
    <label for='{$field_id}'>{$label}</label>
    <figure id='cd-tax-image-wrapper'></figure>
    <input type='hidden' id='{$field_id}' name='{$field_id}' value=''>
    {$this->taxImageFieldButtons($field_id)}
    <hr>
    </div>
    ";
  }

  /**
  * @internal Function to add the image field to the edit fields using the hook {$taxonomy}_edit_form_fields
  */
  public function taxEditImageFieldAction($term) {
    $field_id   = "tax__{$term->taxonomy}-image-id";
    $image_id   = get_term_meta( $term->term_id, $field_id, true );
    $image_id   = filter_var($image_id, FILTER_VALIDATE_INT);
    $label      = __(' Image', 'larodiel');
    $image_html = "";

    if($image_id) {
      $image_html = wp_get_attachment_image( $image_id, 'thumbnail');
    }

    echo "
    <tr class='form-field term-group-wrap'>
    <th scope='row'>
    <label for='{$field_id}'>{$label}</label>
    </th>
    <td>
    <input type='hidden' id='{$field_id}' name='{$field_id}' value='{$image_id}'>
    <figure id='cd-tax-image-wrapper'>
    {$image_html}
    </figure>
    {$this->taxImageFieldButtons($field_id)}
    </td>
    </tr>
    ";
  }

  /**
  * @internal Function to add the image id to database
  */
  public function taxImageSaveAction($termID) {
    $tax      = get_term($termID);
    $field_id = "tax__{$tax->taxonomy}-image-id";
    $image_id = filter_input(INPUT_POST, $field_id, FILTER_VALIDATE_INT);

    if($image_id){
      add_term_meta($termID, $field_id, $image_id, true);
    }
  }

  /**
  * @internal Function to update the image id to database
  */
  public function taxImageUpdateAction( $termID ) {
    $tax      = get_term($termID);
    $field_id = "tax__{$tax->taxonomy}-image-id";
    $image_id = filter_input(INPUT_POST, $field_id, FILTER_VALIDATE_INT);

    if ($image_id) {
      update_term_meta( $termID, $field_id, $image_id);
      return true;
    }

    update_term_meta( $termID, $field_id, '' );
  }

  /**
  * Add a image field insde a taxonomy/category/tag
  * The field name will be "tax__{<taxonomy_slug>}-image-id"
  * To get the value to the category for example, will be
  * `get_term_meta($catID, 'tax__category-image-id', true );`
  *
  * @param string $tax_slug Taxonomy slug
  * @return void
  */
  public function addTaxImage($taxSlug) {
    add_action('admin_enqueue_scripts', function(){
      wp_enqueue_media();
    });

    add_action("{$taxSlug}_add_form_fields", array( $this, 'taxImageFieldAction' ), 10);
    add_action("{$taxSlug}_edit_form_fields", [$this, 'taxEditImageFieldAction'], 40);
    add_action("created_{$taxSlug}", array ( $this, 'taxImageSaveAction'), 10);
    add_action("edited_{$taxSlug}", array ( $this, 'taxImageUpdateAction' ), 10);
  }

  /**
   * It will return the string of background image to be used on `style` HTML attribute
   *
   * @param int|WP_Post $post Post object or ID
   * @param string|array $size Image size string/array (default: 'full')
   * @return string
   */
  function cssBackgroundImageFromPost($post = null, $size = 'full') {
    if(!$post) {
      global $post;
    }
    return 'background-image:url('. get_the_post_thumbnail_url($post, $size) .');';
  }

  /**
   * It will return the string of background image to be used on `style` HTML attribute
   *
   * @param int $attachment_id Attachment ID
   * @param string $size Image size string/array (default: 'full')
   * @return string
   */
  function cssBackgroundImageFromId($attachment_id, $size = 'full') {
      return 'background-image:url('. wp_get_attachment_image_url($attachment_id, $size) .');';
  }

  /**
   * Check if the URL is external
   *
   * @param string $url URL to be checked
   * @return boolean
   */
  function isURLExternal($url) {
    $url           = parse_url($url, PHP_URL_HOST);
    $host_url      = $_SERVER['SERVER_NAME'];

    return $url != $host_url || empty($host_url);
  }

  /**
   * Return the dir and dir_uri of the current active theme
   *
   * @return object
   */
  function getThemeDir() {
    $dir     = get_template_directory();
    $dir_uri = get_template_directory_uri();

    if (is_child_theme()) {
      $dir     = get_stylesheet_directory();
      $dir_uri = get_stylesheet_directory_uri();
    }

    return (object) [
      'dir'     => $dir,
      'dir_uri' => $dir_uri
    ];
  }

  /**
   * Change the byte int to a human readable
   *
   * @param int $bytes
   * @return string
   */
  function bytesToHuman($bytes)
  {
      $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
      for ($i = 0; $bytes > 1024; $i++) $bytes /= 1024;
      return round($bytes, 2) . ' ' . $units[$i];
  }
}
