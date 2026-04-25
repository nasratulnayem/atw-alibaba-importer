<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AWI_Scrape_Importer {
	/**
	 * Imports products from an Alibaba search URL by extracting embedded JSON objects from the page HTML.
	 *
	 * Note: This can break if Alibaba changes their page structure, adds bot protection, or blocks requests.
	 */
	public static function handle_search_import(): array {
		$messages = array();
		$imported = 0;
		$updated  = 0;
		$errors   = 0;

		$cap = class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options';
		if ( ! current_user_can( $cap ) ) {
			return array(
				'imported' => 0,
				'updated'  => 0,
				'errors'   => 1,
				'messages' => array( 'You do not have permission to import products.' ),
			);
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			return array(
				'imported' => 0,
				'updated'  => 0,
				'errors'   => 1,
				'messages' => array( 'WooCommerce is not active.' ),
			);
		}

		$url = isset( $_POST['awi_search_url'] ) ? trim( (string) wp_unslash( $_POST['awi_search_url'] ) ) : '';
		if ( $url === '' ) {
			return array(
				'imported' => 0,
				'updated'  => 0,
				'errors'   => 1,
				'messages' => array( 'Missing search URL.' ),
			);
		}

		$update_existing = ! empty( $_POST['awi_update_existing'] );
		$download_images = ! empty( $_POST['awi_download_images'] );
		$debug           = ! empty( $_POST['awi_debug'] );
		$max_products    = isset( $_POST['awi_max_products'] ) ? (int) $_POST['awi_max_products'] : 48;
		if ( $max_products <= 0 ) {
			$max_products = 48;
		}
		$max_products = min( 500, $max_products );

		$validate = self::validate_alibaba_url( $url );
		if ( is_wp_error( $validate ) ) {
			return array(
				'imported' => 0,
				'updated'  => 0,
				'errors'   => 1,
				'messages' => array( $validate->get_error_message() ),
			);
		}

		$cookie_header = isset( $_POST['awi_cookie_header'] ) ? trim( (string) wp_unslash( $_POST['awi_cookie_header'] ) ) : '';
		$cookie_header = self::normalize_cookie_header( $cookie_header );
		$extra_headers = array();
		if ( $cookie_header !== '' ) {
			// Raw Cookie header string: "a=b; c=d; ..."
			$extra_headers['Cookie'] = $cookie_header;
		}
		$extra_headers['Referer'] = $url;

		$body = self::fetch_html( $url, $messages, $extra_headers, $debug );
		if ( $body === null ) {
			return array(
				'imported' => 0,
				'updated'  => 0,
				'errors'   => 1,
				'messages' => $messages ? $messages : array( 'Failed to fetch URL.' ),
			);
		}

		// If we're clearly served bot protection, be explicit about next steps.
		if ( self::body_looks_like_bot_protection( $body ) ) {
			$messages[] = 'Alibaba returned a CAPTCHA/bot-protection page for this server request.';
			if ( $cookie_header === '' ) {
				$messages[] = 'Fix: paste the browser Cookie header into "Cookie (Optional)" OR use "Import From Capture File".';
			} else {
				$messages[] = 'Cookie was provided but the response still looks like bot protection. Try refreshing cookies or use the capture-file importer.';
			}
		}

		$items = self::extract_products_from_html( $body, $max_products, $messages, $debug );
		if ( ! $items ) {
			$messages[] = 'No products found on the page (possible bot protection, login wall, or page structure change).';
			return array(
				'imported' => 0,
				'updated'  => 0,
				'errors'   => 1,
				'messages' => $messages,
			);
		}

		foreach ( $items as $item ) {
			try {
				$res = self::upsert_product_from_item( $item, $update_existing, $download_images, $messages );
				if ( $res === 'imported' ) {
					$imported++;
				} elseif ( $res === 'updated' ) {
					$updated++;
				} else {
					$errors++;
				}
			} catch ( Throwable $e ) {
				$errors++;
				$messages[] = sprintf( 'Error for productId %s: %s', (string) ( $item['productId'] ?? '-' ), $e->getMessage() );
			}
		}

		return array(
			'imported' => $imported,
			'updated'  => $updated,
			'errors'   => $errors,
			'messages' => $messages,
		);
	}

	/**
	 * Import from a saved HTML/capture file (useful when live fetch is blocked by bot protection).
	 */
	public static function handle_capture_file_import(): array {
		$messages = array();
		$imported = 0;
		$updated  = 0;
		$errors   = 0;

		$cap = class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options';
		if ( ! current_user_can( $cap ) ) {
			return array(
				'imported' => 0,
				'updated'  => 0,
				'errors'   => 1,
				'messages' => array( 'You do not have permission to import products.' ),
			);
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			return array(
				'imported' => 0,
				'updated'  => 0,
				'errors'   => 1,
				'messages' => array( 'WooCommerce is not active.' ),
			);
		}

		if ( empty( $_FILES['awi_capture_file'] ) || empty( $_FILES['awi_capture_file']['tmp_name'] ) ) {
			return array(
				'imported' => 0,
				'updated'  => 0,
				'errors'   => 1,
				'messages' => array( 'No capture file uploaded.' ),
			);
		}

		$update_existing = ! empty( $_POST['awi_update_existing'] );
		$download_images = ! empty( $_POST['awi_download_images'] );
		$debug           = ! empty( $_POST['awi_debug'] );
		$max_products    = isset( $_POST['awi_max_products'] ) ? (int) $_POST['awi_max_products'] : 48;
		if ( $max_products <= 0 ) {
			$max_products = 48;
		}
		$max_products = min( 500, $max_products );

		$tmp_name = (string) $_FILES['awi_capture_file']['tmp_name'];
		$body     = file_get_contents( $tmp_name );
		if ( ! is_string( $body ) || $body === '' ) {
			return array(
				'imported' => 0,
				'updated'  => 0,
				'errors'   => 1,
				'messages' => array( 'Failed to read uploaded file.' ),
			);
		}

		$items = self::extract_products_from_html( $body, $max_products, $messages, $debug );
		if ( ! $items ) {
			$messages[] = 'No products found in the capture file.';
			return array(
				'imported' => 0,
				'updated'  => 0,
				'errors'   => 1,
				'messages' => $messages,
			);
		}

		foreach ( $items as $item ) {
			try {
				$res = self::upsert_product_from_item( $item, $update_existing, $download_images, $messages );
				if ( $res === 'imported' ) {
					$imported++;
				} elseif ( $res === 'updated' ) {
					$updated++;
				} else {
					$errors++;
				}
			} catch ( Throwable $e ) {
				$errors++;
				$messages[] = sprintf( 'Error for productId %s: %s', (string) ( $item['productId'] ?? '-' ), $e->getMessage() );
			}
		}

		return array(
			'imported' => $imported,
			'updated'  => $updated,
			'errors'   => $errors,
			'messages' => $messages,
		);
	}

	private static function validate_alibaba_url( string $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'awi_bad_url', 'Invalid URL.' );
		}
		$host = strtolower( trim( (string) $parts['host'] ) );
		$host = rtrim( $host, '.' );

		// Allow: alibaba.com, www.alibaba.com, any *.alibaba.com
		if ( ! preg_match( '/(^|\\.)alibaba\\.com$/', $host ) ) {
			return new WP_Error( 'awi_not_alibaba', 'URL must be on alibaba.com (host=' . $host . ').' );
		}
		return true;
	}

	private static function fetch_html( string $url, array &$messages, array $extra_headers = array(), bool $debug = false ): ?string {
		$args = array(
			'timeout'     => 30,
			'redirection' => 5,
			'headers'     => array(
				'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36',
				'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				'Accept-Language' => 'en-US,en;q=0.9',
			),
		);

		if ( $extra_headers ) {
			$args['headers'] = array_merge( $args['headers'], $extra_headers );
		}

		$resp = wp_remote_get( $url, $args );
		if ( is_wp_error( $resp ) ) {
			$messages[] = 'Fetch error: ' . $resp->get_error_message();
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $debug ) {
			$final_url = (string) wp_remote_retrieve_header( $resp, 'location' );
			$messages[] = 'Debug: HTTP ' . $code . ( $final_url !== '' ? ( ', location=' . $final_url ) : '' );
		}
		if ( $code < 200 || $code >= 300 ) {
			$messages[] = 'Fetch failed (HTTP ' . $code . ').';
			return null;
		}

		$body = (string) wp_remote_retrieve_body( $resp );
		if ( $body === '' ) {
			$messages[] = 'Empty response body.';
			return null;
		}

		if ( $debug ) {
			$messages[] = 'Debug: body_bytes=' . strlen( $body );
			$preview    = substr( preg_replace( '/\\s+/', ' ', $body ), 0, 300 );
			$messages[] = 'Debug: body_preview=' . $preview;
		}

		return $body;
	}

	/**
	 * Extracts product JSON objects embedded in the HTML.
	 *
	 * Based on your capture: objects include keys like:
	 * - productId / id
	 * - title
	 * - detailUrl
	 * - imageUrl / multiImage
	 * - localOriginalPriceRangeStr / originalMinPrice
	 */
	private static function extract_products_from_html( string $html, int $limit, array &$messages, bool $debug = false ): array {
		$patterns = array(
			// JSON-encoded slashes (common in embedded JSON): "//www.alibaba.com/product-detail/..." as "\/\/www.alibaba.com\/product-detail\/"
			'escaped'   => '/\"detailUrl\"\\s*:\\s*\"\\\\\\/\\\\\\/www\\.alibaba\\.com\\\\\\/product-detail\\\\\\//',
			// Unescaped slashes: "//www.alibaba.com/product-detail/..."
			'unescaped' => '/\"detailUrl\"\\s*:\\s*\"\\/\\/www\\.alibaba\\.com\\/product-detail\\//',
		);

		$hits = array();
		foreach ( $patterns as $label => $pattern ) {
			$ok = preg_match_all( $pattern, $html, $m, PREG_OFFSET_CAPTURE );
			if ( $debug ) {
				$messages[] = 'Debug: detailUrl_hits_' . $label . '=' . (int) $ok;
			}
			if ( $ok && ! empty( $m[0] ) ) {
				foreach ( $m[0] as $hit ) {
					$hits[] = $hit;
				}
			}
		}

		if ( $debug ) {
			$lower = strtolower( $html );
			if ( strpos( $lower, 'captcha' ) !== false ) {
				$messages[] = 'Debug: body_contains=captcha';
			}
			if ( strpos( $lower, 'unusual traffic' ) !== false ) {
				$messages[] = 'Debug: body_contains=unusual traffic';
			}
			if ( strpos( $lower, 'verify' ) !== false && strpos( $lower, 'human' ) !== false ) {
				$messages[] = 'Debug: body_contains=verify_human';
			}
			if ( strpos( $lower, 'robot' ) !== false ) {
				$messages[] = 'Debug: body_contains=robot';
			}
			if ( strpos( $lower, 'signin' ) !== false || strpos( $lower, 'login' ) !== false ) {
				$messages[] = 'Debug: body_contains=login';
			}
		}

		if ( ! $hits ) {
			return array();
		}

		$seen  = array();
		$items = array();

		foreach ( $hits as $hit ) {
			$pos = (int) $hit[1];
			$obj = self::extract_json_object_containing_pos( $html, $pos );
			if ( ! is_array( $obj ) ) {
				continue;
			}

			$product_id = isset( $obj['productId'] ) ? (string) $obj['productId'] : ( isset( $obj['id'] ) ? (string) $obj['id'] : '' );
			$title      = isset( $obj['title'] ) ? trim( (string) $obj['title'] ) : '';
			$detail_url = isset( $obj['detailUrl'] ) ? (string) $obj['detailUrl'] : '';
			$image_url  = isset( $obj['imageUrl'] ) ? (string) $obj['imageUrl'] : '';
			$multi      = isset( $obj['multiImage'] ) && is_array( $obj['multiImage'] ) ? $obj['multiImage'] : array();

			if ( $product_id === '' || $title === '' || $detail_url === '' ) {
				continue;
			}
			if ( isset( $seen[ $product_id ] ) ) {
				continue;
			}
			$seen[ $product_id ] = true;

			$images = array();
			foreach ( $multi as $u ) {
				$u = self::normalize_url( (string) $u );
				if ( $u !== '' ) {
					$images[] = $u;
				}
			}
			if ( ! $images && $image_url !== '' ) {
				$images[] = self::normalize_url( $image_url );
			}

			$items[] = array(
				'productId'        => $product_id,
				'title'            => $title,
				'detailUrl'        => self::normalize_url( $detail_url ),
				'images'           => array_values( array_unique( array_filter( $images ) ) ),
				'companyName'      => isset( $obj['companyName'] ) ? (string) $obj['companyName'] : '',
				'priceRaw'         => isset( $obj['originalMinPrice'] ) ? (string) $obj['originalMinPrice'] : ( isset( $obj['localOriginalPriceRangeStr'] ) ? (string) $obj['localOriginalPriceRangeStr'] : '' ),
				'minOrderQuality'  => isset( $obj['minOrderQuality'] ) ? (string) $obj['minOrderQuality'] : '',
				'minOrderUnit'     => isset( $obj['minOrderUnit'] ) ? (string) $obj['minOrderUnit'] : '',
			);

			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		$messages[] = 'Found ' . count( $items ) . ' products on the page.';
		return $items;
	}

	private static function extract_json_object_containing_pos( string $s, int $pos ): ?array {
		$max_back = 50000;
		$start_at = max( 0, $pos - $max_back );

		// Scan backward looking for a '{' that starts a JSON object containing $pos.
		for ( $start = $pos; $start >= $start_at; $start-- ) {
			if ( $s[ $start ] !== '{' ) {
				continue;
			}

			$end = self::find_matching_brace_end( $s, $start, $pos );
			if ( $end === null ) {
				continue;
			}

			$txt = substr( $s, $start, $end - $start );
			$obj = json_decode( $txt, true );
			if ( is_array( $obj ) ) {
				return $obj;
			}
		}

		return null;
	}

	private static function find_matching_brace_end( string $s, int $start, int $must_contain_pos ): ?int {
		$len   = strlen( $s );
		$bal   = 0;
		$in_q  = false;
		$esc   = false;

		for ( $i = $start; $i < $len; $i++ ) {
			$c = $s[ $i ];

			if ( $in_q ) {
				if ( $esc ) {
					$esc = false;
				} elseif ( $c === '\\\\' ) {
					$esc = true;
				} elseif ( $c === '\"' ) {
					$in_q = false;
				}
				continue;
			}

			if ( $c === '\"' ) {
				$in_q = true;
				continue;
			}

			if ( $c === '{' ) {
				$bal++;
				continue;
			}
			if ( $c === '}' ) {
				$bal--;
				if ( $bal === 0 ) {
					$end = $i + 1;
					if ( $start < $must_contain_pos && $must_contain_pos < $end ) {
						return $end;
					}
					return null;
				}
			}
		}

		return null;
	}

	private static function upsert_product_from_item( array $item, bool $update_existing, bool $download_images, array &$messages ): string {
		$product_id = trim( (string) ( $item['productId'] ?? '' ) );
		$title      = trim( (string) ( $item['title'] ?? '' ) );
		$detail_url = trim( (string) ( $item['detailUrl'] ?? '' ) );

		if ( $product_id === '' || $title === '' ) {
			$messages[] = 'Skipped item: missing productId/title.';
			return 'error';
		}

		$sku        = $product_id;
		$existing   = (int) wc_get_product_id_by_sku( $sku );
		$is_update  = $existing > 0;

		if ( $is_update && ! $update_existing ) {
			$messages[] = sprintf( 'Skipped existing SKU %s (updates disabled).', $sku );
			return 'error';
		}

		$product = $is_update ? wc_get_product( $existing ) : new WC_Product_Simple();
		if ( ! $product ) {
			throw new RuntimeException( 'Failed to create/load product.' );
		}

		$product->set_sku( $sku );
		$product->set_name( $title );

		$product->set_description( '' );

		$price = self::parse_first_number( (string) ( $item['priceRaw'] ?? '' ) );
		if ( $price !== null ) {
			$product->set_regular_price( (string) $price );
		}

		$saved_id = (int) $product->save();
		if ( $saved_id <= 0 ) {
			throw new RuntimeException( 'Failed to save product.' );
		}

		update_post_meta( $saved_id, '_awi_source', 'alibaba_search_scrape' );
		update_post_meta( $saved_id, '_awi_alibaba_product_id', $product_id );
		if ( $detail_url !== '' ) {
			update_post_meta( $saved_id, '_awi_alibaba_detail_url', $detail_url );
		}

		if ( $download_images && ! empty( $item['images'] ) && is_array( $item['images'] ) ) {
			$attachment_ids = self::sideload_images( $item['images'], $saved_id, $messages );
			if ( $attachment_ids ) {
				$product = wc_get_product( $saved_id );
				if ( $product ) {
					if ( ! $product->get_image_id() && isset( $attachment_ids[0] ) ) {
						$product->set_image_id( (int) $attachment_ids[0] );
					}
					if ( count( $attachment_ids ) > 1 ) {
						$product->set_gallery_image_ids( array_map( 'intval', array_slice( $attachment_ids, 1 ) ) );
					}
					$product->save();
				}
			}
		}

		$messages[] = sprintf( '%s product #%d (Alibaba productId %s)', $is_update ? 'Updated' : 'Imported', $saved_id, $product_id );
		return $is_update ? 'updated' : 'imported';
	}

	private static function normalize_url( string $url ): string {
		$url = trim( $url );
		if ( $url === '' ) {
			return '';
		}
		if ( substr( $url, 0, 2 ) === '//' ) {
			return 'https:' . $url;
		}
		return $url;
	}

	private static function parse_first_number( string $s ): ?float {
		// Extract the first number from strings like "BDT 244.56-305.70".
		if ( ! preg_match( '/([0-9]+(?:\\.[0-9]+)?)/', $s, $m ) ) {
			return null;
		}
		return (float) $m[1];
	}

	private static function normalize_cookie_header( string $cookie_header ): string {
		$cookie_header = trim( $cookie_header );
		if ( $cookie_header === '' ) {
			return '';
		}

		// Accept either raw cookie string or a copied header line like "Cookie: a=b; c=d".
		if ( stripos( $cookie_header, 'cookie:' ) === 0 ) {
			$cookie_header = trim( substr( $cookie_header, strlen( 'cookie:' ) ) );
		}

		// Strip CR and LF explicitly before any other processing to prevent HTTP header injection.
		// A raw \r\n sequence inside a header value would split the HTTP request and inject arbitrary headers.
		$cookie_header = str_replace( array( "\r", "\n", "\0" ), '', $cookie_header );

		// Collapse any remaining runs of horizontal whitespace (tabs, spaces from DevTools copy).
		$cookie_header = (string) preg_replace( '/[ \t]+/', ' ', $cookie_header );
		$cookie_header = trim( $cookie_header );

		// Hard length cap — real cookie headers are rarely over 8 KB; anything larger is suspicious.
		if ( strlen( $cookie_header ) > 8192 ) {
			$cookie_header = substr( $cookie_header, 0, 8192 );
		}

		return $cookie_header;
	}

	private static function body_looks_like_bot_protection( string $body ): bool {
		$lower = strtolower( $body );
		if ( strpos( $lower, 'captcha' ) !== false ) {
			return true;
		}
		if ( strpos( $lower, 'unusual traffic' ) !== false ) {
			return true;
		}
		if ( strpos( $lower, 'verify' ) !== false && strpos( $lower, 'human' ) !== false ) {
			return true;
		}
		return false;
	}

	private static function sideload_images( array $urls, int $product_post_id, array &$messages ): array {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_ids = array();

		foreach ( $urls as $url ) {
			$url = (string) $url;
			if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
				$messages[] = sprintf( 'Invalid image URL skipped: %s', $url );
				continue;
			}

			$att_id = media_sideload_image( $url, $product_post_id, null, 'id' );
			if ( is_wp_error( $att_id ) ) {
				$messages[] = sprintf( 'Image download failed (%s): %s', $url, $att_id->get_error_message() );
				continue;
			}
			$attachment_ids[] = (int) $att_id;
		}

		return $attachment_ids;
	}
}
