<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AWI_Importer {
	public static function handle_csv_import(): array {
		$messages = array();
		$imported = 0;
		$updated  = 0;
		$errors   = 0;

		if ( ! class_exists( 'WooCommerce' ) ) {
			return array(
				'imported' => 0,
				'updated'  => 0,
				'errors'   => 1,
				'messages' => array( 'WooCommerce is not active.' ),
			);
		}

		if ( empty( $_FILES['awi_csv_file'] ) || empty( $_FILES['awi_csv_file']['tmp_name'] ) ) {
			return array(
				'imported' => 0,
				'updated'  => 0,
				'errors'   => 1,
				'messages' => array( 'No CSV file uploaded.' ),
			);
		}

		$update_existing = ! empty( $_POST['awi_update_existing'] );
		$download_images = ! empty( $_POST['awi_download_images'] );
		$skip_header     = ! empty( $_POST['awi_skip_first_row'] );

		$tmp_name = (string) $_FILES['awi_csv_file']['tmp_name'];
		$fh       = fopen( $tmp_name, 'rb' );
		if ( ! $fh ) {
			return array(
				'imported' => 0,
				'updated'  => 0,
				'errors'   => 1,
				'messages' => array( 'Failed to read uploaded file.' ),
			);
		}

		$row_index = 0;
		$headers   = array();

		while ( ( $row = fgetcsv( $fh ) ) !== false ) {
			$row_index++;

			// Skip entirely empty rows.
			if ( self::is_empty_row( $row ) ) {
				continue;
			}

			if ( $row_index === 1 ) {
				$headers = self::normalize_headers( $row );
				if ( $skip_header ) {
					continue;
				}
			}

			try {
				$data = self::row_to_assoc( $headers, $row );
				$res  = self::upsert_product( $data, $update_existing, $download_images, $messages );
				if ( $res === 'imported' ) {
					$imported++;
				} elseif ( $res === 'updated' ) {
					$updated++;
				} else {
					$errors++;
				}
			} catch ( Throwable $e ) {
				$errors++;
				$messages[] = sprintf( 'Row %d: %s', $row_index, $e->getMessage() );
			}
		}

		fclose( $fh );

		return array(
			'imported' => $imported,
			'updated'  => $updated,
			'errors'   => $errors,
			'messages' => $messages,
		);
	}

	private static function upsert_product( array $data, bool $update_existing, bool $download_images, array &$messages ): string {
		$sku  = trim( (string) ( $data['sku'] ?? '' ) );
		$name = trim( (string) ( $data['name'] ?? '' ) );

		if ( $sku === '' && $name === '' ) {
			$messages[] = 'Skipped row: missing sku and name.';
			return 'error';
		}

		$product_id = 0;
		if ( $sku !== '' ) {
			$product_id = (int) wc_get_product_id_by_sku( $sku );
		}

		$is_update = $product_id > 0;
		if ( $is_update && ! $update_existing ) {
			$messages[] = sprintf( 'Skipped existing SKU %s (updates disabled).', $sku );
			return 'error';
		}

		$product = $is_update ? wc_get_product( $product_id ) : new WC_Product_Simple();
		if ( ! $product ) {
			throw new RuntimeException( 'Failed to create/load product.' );
		}

		if ( $sku !== '' ) {
			$product->set_sku( $sku );
		}
		if ( $name !== '' ) {
			$product->set_name( $name );
		}

		$desc = (string) ( $data['description'] ?? '' );
		if ( $desc !== '' ) {
			$product->set_description( $desc );
		}

		$short = (string) ( $data['short_description'] ?? '' );
		if ( $short !== '' ) {
			$product->set_short_description( $short );
		}

		$regular = self::num_or_null( $data['regular_price'] ?? null );
		if ( $regular !== null ) {
			$product->set_regular_price( (string) $regular );
		}

		$sale = self::num_or_null( $data['sale_price'] ?? null );
		if ( $sale !== null ) {
			$product->set_sale_price( (string) $sale );
		}

		$stock_qty = self::int_or_null( $data['stock_quantity'] ?? null );
		if ( $stock_qty !== null ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $stock_qty );
			$product->set_stock_status( $stock_qty > 0 ? 'instock' : 'outofstock' );
		}

		$product_id = (int) $product->save();
		if ( $product_id <= 0 ) {
			throw new RuntimeException( 'Failed to save product.' );
		}

		// Taxonomy assignments.
		if ( ! empty( $data['category'] ) ) {
			$cat = trim( (string) $data['category'] );
			if ( $cat !== '' ) {
				wp_set_object_terms( $product_id, array( $cat ), 'product_cat', false );
			}
		}
		if ( ! empty( $data['tags'] ) ) {
			$tags = array_filter( array_map( 'trim', explode( ',', (string) $data['tags'] ) ) );
			if ( $tags ) {
				wp_set_object_terms( $product_id, $tags, 'product_tag', false );
			}
		}

		// Images.
		if ( $download_images && ! empty( $data['image_urls'] ) ) {
			$image_urls = array_filter( array_map( 'trim', explode( ',', (string) $data['image_urls'] ) ) );
			if ( $image_urls ) {
				$attachment_ids = self::sideload_images( $image_urls, $product_id, $messages );
				if ( $attachment_ids ) {
					$product = wc_get_product( $product_id );
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
		}

		$messages[] = sprintf(
			'%s product #%d (%s)',
			$is_update ? 'Updated' : 'Imported',
			$product_id,
			$sku !== '' ? 'SKU ' . $sku : $name
		);

		return $is_update ? 'updated' : 'imported';
	}

	private static function sideload_images( array $urls, int $product_id, array &$messages ): array {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_ids = array();

		foreach ( $urls as $url ) {
			if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
				$messages[] = sprintf( 'Invalid image URL skipped: %s', $url );
				continue;
			}

			// media_sideload_image returns HTML on success; use the "id" return type to get attachment ID.
			$att_id = media_sideload_image( $url, $product_id, null, 'id' );
			if ( is_wp_error( $att_id ) ) {
				$messages[] = sprintf( 'Image download failed (%s): %s', $url, $att_id->get_error_message() );
				continue;
			}
			$attachment_ids[] = (int) $att_id;
		}

		return $attachment_ids;
	}

	private static function normalize_headers( array $header_row ): array {
		$out = array();
		foreach ( $header_row as $h ) {
			$h     = strtolower( trim( (string) $h ) );
			$h     = preg_replace( '/\s+/', '_', $h );
			$out[] = $h;
		}
		return $out;
	}

	private static function row_to_assoc( array $headers, array $row ): array {
		$data = array();
		foreach ( $headers as $i => $k ) {
			if ( $k === '' ) {
				continue;
			}
			$data[ $k ] = isset( $row[ $i ] ) ? (string) $row[ $i ] : '';
		}
		return $data;
	}

	private static function is_empty_row( array $row ): bool {
		foreach ( $row as $cell ) {
			if ( trim( (string) $cell ) !== '' ) {
				return false;
			}
		}
		return true;
	}

	private static function num_or_null( $v ): ?float {
		if ( $v === null ) {
			return null;
		}
		$s = trim( (string) $v );
		if ( $s === '' ) {
			return null;
		}
		$s = str_replace( array( '$', '£', '€' ), '', $s );
		$s = str_replace( ' ', '', $s );
		if ( ! is_numeric( $s ) ) {
			return null;
		}
		return (float) $s;
	}

	private static function int_or_null( $v ): ?int {
		if ( $v === null ) {
			return null;
		}
		$s = trim( (string) $v );
		if ( $s === '' ) {
			return null;
		}
		if ( ! is_numeric( $s ) ) {
			return null;
		}
		return (int) $s;
	}
}

