<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AWI_Frontend {
	public static function init(): void {
		add_action( 'woocommerce_product_thumbnails', array( __CLASS__, 'render_product_video_in_gallery' ), 25 );
		add_action( 'wp_footer', array( __CLASS__, 'render_video_fallback_script' ), 99 );
	}

	public static function render_product_video_in_gallery(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$product_id = get_the_ID();
		if ( ! $product_id ) {
			return;
		}

		$video_url = trim( (string) get_post_meta( $product_id, '_awi_video_url', true ) );
		if ( $video_url === '' ) {
			$video_url = trim( (string) get_post_meta( $product_id, '_product_video_url', true ) );
		}
		if ( $video_url === '' ) {
			return;
		}

		$poster_url = trim( (string) get_post_meta( $product_id, '_awi_video_poster', true ) );
		$thumb_url  = $poster_url !== '' ? $poster_url : get_the_post_thumbnail_url( $product_id, 'woocommerce_thumbnail' );
		$poster     = $poster_url !== '' ? ' poster="' . esc_attr( esc_url( $poster_url ) ) . '"' : '';
		$thumb_attr = $thumb_url !== '' ? ' data-thumb="' . esc_attr( esc_url( $thumb_url ) ) . '"' : '';
		?>
		<div class="woocommerce-product-gallery__image awi-product-gallery-video awi-product-gallery-video-slide"<?php echo $thumb_attr; ?> data-thumb-alt="Product video">
			<video controls playsinline preload="metadata"<?php echo $poster; ?> style="width:100%;height:auto;">
				<source src="<?php echo esc_url( $video_url ); ?>" type="video/mp4">
			</video>
		</div>
		<?php
	}

	public static function render_video_fallback_script(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$product_id = get_the_ID();
		if ( ! $product_id ) {
			return;
		}

		$video_url = trim( (string) get_post_meta( $product_id, '_awi_video_url', true ) );
		if ( $video_url === '' ) {
			$video_url = trim( (string) get_post_meta( $product_id, '_product_video_url', true ) );
		}
		if ( $video_url === '' ) {
			return;
		}

		$poster_url = trim( (string) get_post_meta( $product_id, '_awi_video_poster', true ) );
		$data       = wp_json_encode(
			array(
				'video'  => esc_url_raw( $video_url ),
				'poster' => esc_url_raw( $poster_url ),
			)
		);
		if ( ! is_string( $data ) || $data === '' ) {
			return;
		}
		?>
		<style id="awi-video-thumb-style">
		.flex-control-thumbs li.awi-video-thumb { position: relative; }
		.flex-control-thumbs li.awi-video-thumb::after {
			content: '▶';
			position: absolute;
			right: 6px;
			bottom: 6px;
			width: 18px;
			height: 18px;
			border-radius: 50%;
			background: rgba(0, 0, 0, 0.65);
			color: #fff;
			font-size: 11px;
			line-height: 18px;
			text-align: center;
			pointer-events: none;
		}
		</style>
		<script>
		(function() {
			// Both values in $data are passed through esc_url_raw() before wp_json_encode(),
			// so no XSS vector exists here. Output is intentionally unescaped JSON.
			var d = <?php echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
			if (!d || !d.video) return;

			var selectors = [
				'.woocommerce-product-gallery__wrapper',
				'.wp-block-woocommerce-product-image-gallery',
				'.wc-block-components-product-image'
			];
			var container = null;
			for (var i = 0; i < selectors.length; i++) {
				container = document.querySelector(selectors[i]);
				if (container) break;
			}
			if (!container) {
				container = document.querySelector('.summary.entry-summary');
				if (!container || !container.parentNode) return;
			}

			var wrap = document.querySelector('.awi-product-gallery-video-slide');
			if (!wrap) {
				wrap = document.createElement('div');
				wrap.className = 'woocommerce-product-gallery__image awi-product-gallery-video awi-product-gallery-video-slide';
				wrap.style.marginBottom = '12px';
				var video = document.createElement('video');
				video.setAttribute('controls', 'controls');
				video.setAttribute('playsinline', 'playsinline');
				video.setAttribute('preload', 'metadata');
				video.src = d.video;
				if (d.poster) video.poster = d.poster;
				video.style.width = '100%';
				video.style.height = 'auto';
				wrap.appendChild(video);

				if (container.classList && container.classList.contains('summary') && container.parentNode) {
					container.parentNode.insertBefore(wrap, container);
				} else if (container.firstChild) {
					container.insertBefore(wrap, container.firstChild);
				} else {
					container.appendChild(wrap);
				}
			}

			var thumbs = document.querySelector('.flex-control-thumbs');
			if (thumbs && !thumbs.querySelector('.awi-video-thumb')) {
				var fallbackThumb = '';
				var firstThumb = thumbs.querySelector('li img');
				if (firstThumb && firstThumb.getAttribute('src')) {
					fallbackThumb = firstThumb.getAttribute('src');
				}
				var thumbSrc = d.poster || fallbackThumb;
				if (!thumbSrc) return;

				var slides = container.querySelectorAll('.woocommerce-product-gallery__image');
				var idx = -1;
				for (var i = 0; i < slides.length; i++) {
					if (slides[i] === wrap) {
						idx = i;
						break;
					}
				}

				var li = null;
				var thumbItems = thumbs.querySelectorAll('li');
				if (idx >= 0 && thumbItems[idx]) {
					li = thumbItems[idx];
					var existingImg = li.querySelector('img');
					if (existingImg && !existingImg.getAttribute('src')) {
						existingImg.src = thumbSrc;
					}
				} else {
					li = document.createElement('li');
					var thumb = document.createElement('img');
					thumb.alt = 'Video thumbnail';
					thumb.src = thumbSrc;
					thumb.style.objectFit = 'cover';
					thumb.style.position = 'relative';
					li.appendChild(thumb);
					thumbs.appendChild(li);
				}
				li.classList.add('awi-video-thumb');

				li.addEventListener('click', function(e) {
					e.preventDefault();
					var gallery = document.querySelector('.woocommerce-product-gallery');
					if (window.jQuery && jQuery.fn && jQuery.fn.flexslider && gallery) {
						var $gallery = jQuery(gallery);
						var fs = $gallery.data('flexslider');
						if (fs && idx >= 0) {
							fs.flexAnimate(idx);
							return;
						}
					}
					for (var j = 0; j < slides.length; j++) {
						slides[j].style.display = (slides[j] === wrap) ? '' : 'none';
					}
					var vid = wrap.querySelector('video');
					if (vid && vid.paused) {
						vid.play().catch(function(){});
					}
				});
			}
		})();
		</script>
		<?php
	}
}
