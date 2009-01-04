<?php
/*
 * ImageAdditionEvent:
 *   $user  -- the user adding the image
 *   $image -- the image being added
 *
 * An image is being added to the database
 */
class ImageAdditionEvent extends Event {
	var $user, $image;

	public function ImageAdditionEvent($user, $image) {
		$this->image = $image;
		$this->user = $user;
	}
}


/*
 * ImageDeletionEvent:
 *   $image -- the image being deleted
 *
 * An image is being deleted. Used by things like tags
 * and comments handlers to clean out related rows in
 * their tables
 */
class ImageDeletionEvent extends Event {
	var $image;

	public function ImageDeletionEvent($image) {
		$this->image = $image;
	}
}


/*
 * ThumbnailGenerationEvent:
 * Request a thumb be made for an image
 */
class ThumbnailGenerationEvent extends Event {
	var $hash;
	var $type;

	public function ThumbnailGenerationEvent($hash, $type) {
		$this->hash = $hash;
		$this->type = $type;
	}
}


/*
 * ParseLinkTemplateEvent:
 *   $link     -- the formatted link
 *   $original -- the formatting string, for reference
 *   $image    -- the image who's link is being parsed
 */
class ParseLinkTemplateEvent extends Event {
	var $link, $original;
	var $image;

	public function ParseLinkTemplateEvent($link, $image) {
		$this->link = $link;
		$this->original = $link;
		$this->image = $image;
	}

	public function replace($needle, $replace) {
		$this->link = str_replace($needle, $replace, $this->link);
	}
}


/*
 * A class to handle adding / getting / removing image
 * files from the disk
 */
class ImageIO implements Extension {
// event handling {{{
	public function receive_event(Event $event) {
		if($event instanceof InitExtEvent) {
			global $config;
			$config->set_default_int('thumb_width', 192);
			$config->set_default_int('thumb_height', 192);
			$config->set_default_int('thumb_quality', 75);
			$config->set_default_int('thumb_mem_limit', parse_shorthand_int('8MB'));
			
			$config->set_default_string('image_ilink', '');
			$config->set_default_string('image_tlink', '');
			$config->set_default_string('image_tip', '$tags // $size // $filesize');
			$config->set_default_string('upload_collision_handler', 'error');
		}

		if($event instanceof PageRequestEvent) {
			$num = $event->get_arg(0);
			$matches = array();
			if(!is_null($num) && preg_match("/(\d+)/", $num, $matches)) {
				$num = $matches[1];
				
				if($event->page_matches("image")) {
					$this->send_file($num, "image");
				}
				else if($event->page_matches("thumb")) {
					$this->send_file($num, "thumb");
				}
			}
		}

		if($event instanceof ImageAdditionEvent) {
			$error = $this->add_image($event->image);
			if(!empty($error)) throw new UploadException($error);
		}

		if($event instanceof ImageDeletionEvent) {
			$event->image->delete();
		}
		
		if($event instanceof SetupBuildingEvent) {
			$sb = new SetupBlock("Image Options");
			$sb->position = 30;
			// advanced only
			//$sb->add_text_option("image_ilink", "Image link: ");
			//$sb->add_text_option("image_tlink", "<br>Thumbnail link: ");
			$sb->add_text_option("image_tip", "Image tooltip: ");
			$sb->add_choice_option("upload_collision_handler", array('Error'=>'error', 'Merge'=>'merge'), "<br>Upload collision handler: ");
			$event->panel->add_block($sb);

			$thumbers = array();
			$thumbers['Built-in GD'] = "gd";
			$thumbers['ImageMagick'] = "convert";

			$sb = new SetupBlock("Thumbnailing");
			$sb->add_choice_option("thumb_engine", $thumbers, "Engine: ");

			$sb->add_label("<br>Size ");
			$sb->add_int_option("thumb_width");
			$sb->add_label(" x ");
			$sb->add_int_option("thumb_height");
			$sb->add_label(" px at ");
			$sb->add_int_option("thumb_quality");
			$sb->add_label(" % quality ");

			$sb->add_shorthand_int_option("thumb_mem_limit", "<br>Max memory use: ");

			$event->panel->add_block($sb);
		}
	}
// }}}
// add image {{{
	private function add_image($image) {
		global $page;
		global $user;
		global $database;
		global $config;

		/*
		 * Validate things
		 */
		if(strlen(trim($image->source)) == 0) {
			$image->source = null;
		}
		if(!empty($image->source)) {
			if(!preg_match("#^(https?|ftp)://#", $image->source)) {
				$error = "Image's source isn't a valid URL";
				return $error;
			}
		}

		/*
		 * Check for an existing image
		 */
		$existing = Image::by_hash($config, $database, $image->hash);
		if(!is_null($existing)) {
			$handler = $config->get_string("upload_collision_handler");
			if($handler == "merge") {
				$merged = array_merge($image->get_tag_array(), $existing->get_tag_array());
				send_event(new TagSetEvent($existing->id, $merged));
				return null;
			}
			else {
				$error = "Image <a href='".make_link("post/view/{$existing->id}")."'>{$existing->id}</a> ".
						"already has hash {$image->hash}:<p>".Themelet::build_thumb_html($existing);
				return $error;
			}
		}

		// actually insert the info
		$database->Execute(
				"INSERT INTO images(
					owner_id, owner_ip, filename, filesize,
					hash, ext, width, height, posted, source)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?, now(), ?)",
				array($user->id, $_SERVER['REMOTE_ADDR'], $image->filename, $image->filesize,
						$image->hash, $image->ext, $image->width, $image->height, $image->source));
		$image->id = $database->db->Insert_ID();

		send_event(new TagSetEvent($image->id, $image->get_tag_array()));

		return null;
	}
// }}}
// fetch image {{{
	private function send_file($image_id, $type) {
		global $config;
		global $database;
		$image = Image::by_id($config, $database, $image_id);

		global $page;
		if(!is_null($image)) {
			$page->set_mode("data");
			if($type == "thumb") {
				$page->set_type("image/jpeg");
				$file = $image->get_thumb_filename();
			}
			else {
				$page->set_type($image->get_mime_type());
				$file = $image->get_image_filename();
			}
		
			$page->set_data(file_get_contents($file));

			if(isset($_SERVER["HTTP_IF_MODIFIED_SINCE"])) {
				$if_modified_since = preg_replace('/;.*$/', '', $_SERVER["HTTP_IF_MODIFIED_SINCE"]);
			}
			else {
				$if_modified_since = "";
			}
			$gmdate_mod = gmdate('D, d M Y H:i:s', filemtime($file)) . ' GMT';

			// FIXME: should be $page->blah
			if($if_modified_since == $gmdate_mod) {
				header("HTTP/1.0 304 Not Modified");
			}
			else {
				header("Last-Modified: $gmdate_mod");
				header("Expires: Fri, 2 Sep 2101 12:42:42 GMT"); // War was beginning
			}
		}
		else {
			$page->set_title("Not Found");
			$page->set_heading("Not Found");
			$page->add_block(new Block("Navigation", "<a href='".make_link()."'>Index</a>", "left", 0));
			$page->add_block(new Block("Image not in database",
					"The requested image was not found in the database"));
		}
	}
// }}}
}
add_event_listener(new ImageIO());
?>
