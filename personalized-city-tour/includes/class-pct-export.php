<?php
/**
 * Dependency-free report exporters: DOCX, XLSX (ZipArchive), PDF (built-in
 * minimal writer), CSV fallback. PNG charts are rendered client side.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_Export {

	public static function formats() {
		$f = array(
			'pdf' => 'PDF',
			'csv' => 'CSV',
		);
		if ( class_exists( 'ZipArchive' ) ) {
			$f['docx'] = 'DOCX';
			$f['xlsx'] = 'XLSX';
		}
		return $f;
	}

	public static function send( array $job, $format ) {
		$slug = sanitize_title( $job['title'] );
		$slug = $slug ? $slug : 'report-' . $job['id'];
		switch ( $format ) {
			case 'docx':
				$bin  = self::docx( $job );
				$mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
				break;
			case 'xlsx':
				$bin  = self::xlsx( $job );
				$mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
				break;
			case 'pdf':
				$bin  = self::pdf( $job );
				$mime = 'application/pdf';
				break;
			default:
				$format = 'csv';
				$bin    = self::csv( $job );
				$mime   = 'text/csv; charset=utf-8';
		}
		if ( is_wp_error( $bin ) ) {
			wp_die( esc_html( $bin->get_error_message() ) );
		}
		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . $slug . '.' . $format . '"' );
		header( 'Content-Length: ' . strlen( $bin ) );
		echo $bin; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/* ------------------------------------------------------------------ */

	private static function result( array $job ) {
		$r = $job['result'];
		return array(
			'summary'  => (string) ( $r['summary'] ?? '' ),
			'insights' => (array) ( $r['insights'] ?? array() ),
			'charts'   => (array) ( $r['charts'] ?? array() ),
			'tables'   => (array) ( $r['tables'] ?? array() ),
		);
	}

	public static function csv( array $job ) {
		$r   = self::result( $job );
		$out = fopen( 'php://temp', 'r+' );
		fputcsv( $out, array( $job['title'] ) );
		fputcsv( $out, array( 'Summary', $r['summary'] ) );
		foreach ( $r['insights'] as $i ) {
			fputcsv( $out, array( 'Insight', $i ) );
		}
		foreach ( $r['tables'] as $t ) {
			fputcsv( $out, array() );
			fputcsv( $out, array( $t['title'] ?? '' ) );
			fputcsv( $out, (array) ( $t['columns'] ?? array() ) );
			foreach ( (array) ( $t['rows'] ?? array() ) as $row ) {
				fputcsv( $out, (array) $row );
			}
		}
		foreach ( $r['charts'] as $c ) {
			fputcsv( $out, array() );
			fputcsv( $out, array( $c['title'] ?? '' ) );
			foreach ( (array) ( $c['labels'] ?? array() ) as $i => $label ) {
				fputcsv( $out, array( $label, $c['values'][ $i ] ?? '' ) );
			}
		}
		rewind( $out );
		return "\xEF\xBB\xBF" . stream_get_contents( $out );
	}

	/* ------------------------------------------------------------------ */
	/* DOCX                                                                */
	/* ------------------------------------------------------------------ */

	private static function zip( array $files ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'pct_zip', __( 'ZipArchive is not available on this server; use CSV or PDF.', 'personalized-city-tour' ) );
		}
		$tmp = wp_tempnam( 'pct-export' );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'pct_zip', __( 'Could not create the archive.', 'personalized-city-tour' ) );
		}
		foreach ( $files as $name => $content ) {
			$zip->addFromString( $name, $content );
		}
		$zip->close();
		$bin = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $bin;
	}

	private static function x( $s ) {
		return htmlspecialchars( (string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	private static function docx_p( $text, $style = '' ) {
		$ppr = $style ? '<w:pPr><w:pStyle w:val="' . $style . '"/></w:pPr>' : '';
		return '<w:p>' . $ppr . '<w:r><w:t xml:space="preserve">' . self::x( $text ) . '</w:t></w:r></w:p>';
	}

	private static function docx_table( array $columns, array $rows ) {
		$xml = '<w:tbl><w:tblPr><w:tblStyle w:val="TableGrid"/><w:tblW w:w="0" w:type="auto"/><w:tblBorders>'
			. '<w:top w:val="single" w:sz="4"/><w:left w:val="single" w:sz="4"/><w:bottom w:val="single" w:sz="4"/><w:right w:val="single" w:sz="4"/><w:insideH w:val="single" w:sz="4"/><w:insideV w:val="single" w:sz="4"/>'
			. '</w:tblBorders></w:tblPr>';
		$all = array_merge( array( $columns ), $rows );
		foreach ( $all as $i => $row ) {
			$xml .= '<w:tr>';
			foreach ( (array) $row as $cell ) {
				$run  = '<w:r>' . ( 0 === $i ? '<w:rPr><w:b/></w:rPr>' : '' ) . '<w:t xml:space="preserve">' . self::x( $cell ) . '</w:t></w:r>';
				$xml .= '<w:tc><w:p>' . $run . '</w:p></w:tc>';
			}
			$xml .= '</w:tr>';
		}
		return $xml . '</w:tbl>' . self::docx_p( '' );
	}

	public static function docx( array $job ) {
		$r    = self::result( $job );
		$rtl  = PCT_I18n::is_rtl( $job['request']['lang'] ?? '' );
		$body = self::docx_p( $job['title'], 'Title' );
		$body .= self::docx_p( sprintf( '%s · %s', get_bloginfo( 'name' ), $job['finished'] ) );
		$body .= self::docx_p( __( 'Summary', 'personalized-city-tour' ), 'Heading1' );
		$body .= self::docx_p( $r['summary'] );
		if ( $r['insights'] ) {
			$body .= self::docx_p( __( 'Insights', 'personalized-city-tour' ), 'Heading1' );
			foreach ( $r['insights'] as $i ) {
				$body .= self::docx_p( '• ' . $i );
			}
		}
		foreach ( $r['charts'] as $c ) {
			$body .= self::docx_p( (string) ( $c['title'] ?? '' ), 'Heading2' );
			$rows  = array();
			foreach ( (array) ( $c['labels'] ?? array() ) as $i => $label ) {
				$rows[] = array( $label, $c['values'][ $i ] ?? '' );
			}
			$body .= self::docx_table( array( __( 'Label', 'personalized-city-tour' ), __( 'Value', 'personalized-city-tour' ) ), $rows );
		}
		foreach ( $r['tables'] as $t ) {
			$body .= self::docx_p( (string) ( $t['title'] ?? '' ), 'Heading2' );
			$body .= self::docx_table( (array) ( $t['columns'] ?? array() ), (array) ( $t['rows'] ?? array() ) );
		}
		$body .= self::docx_p( sprintf( '%s — %s', PCT_AUTHOR, PCT_CREDITS ) );
		$sect  = '<w:sectPr>' . ( $rtl ? '<w:bidi/>' : '' ) . '<w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1200" w:right="1200" w:bottom="1200" w:left="1200"/></w:sectPr>';
		$doc   = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $body . $sect . '</w:body></w:document>';

		$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Tahoma"/><w:sz w:val="22"/></w:rPr></w:rPrDefault></w:docDefaults>'
			. '<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:rPr><w:b/><w:sz w:val="40"/></w:rPr></w:style>'
			. '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:pPr><w:spacing w:before="240"/></w:pPr><w:rPr><w:b/><w:sz w:val="30"/></w:rPr></w:style>'
			. '<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:pPr><w:spacing w:before="200"/></w:pPr><w:rPr><w:b/><w:sz w:val="26"/></w:rPr></w:style>'
			. '<w:style w:type="table" w:styleId="TableGrid"><w:name w:val="Table Grid"/></w:style></w:styles>';

		return self::zip(
			array(
				'[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>',
				'_rels/.rels'         => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
				'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
				'word/document.xml'   => $doc,
				'word/styles.xml'     => $styles,
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* XLSX                                                                */
	/* ------------------------------------------------------------------ */

	private static function sheet_xml( array $rows ) {
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
		foreach ( $rows as $ri => $row ) {
			$xml .= '<row r="' . ( $ri + 1 ) . '">';
			foreach ( array_values( (array) $row ) as $ci => $cell ) {
				$ref = self::col_letter( $ci ) . ( $ri + 1 );
				if ( is_numeric( $cell ) && '' !== $cell ) {
					$xml .= '<c r="' . $ref . '"><v>' . (float) $cell . '</v></c>';
				} else {
					$xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . self::x( $cell ) . '</t></is></c>';
				}
			}
			$xml .= '</row>';
		}
		return $xml . '</sheetData></worksheet>';
	}

	private static function col_letter( $i ) {
		$s = '';
		$i++;
		while ( $i > 0 ) {
			$m  = ( $i - 1 ) % 26;
			$s  = chr( 65 + $m ) . $s;
			$i  = (int) ( ( $i - $m - 1 ) / 26 );
		}
		return $s;
	}

	public static function xlsx( array $job ) {
		$r      = self::result( $job );
		$sheets = array();
		$rows   = array( array( $job['title'] ), array( __( 'Summary', 'personalized-city-tour' ), $r['summary'] ) );
		foreach ( $r['insights'] as $i ) {
			$rows[] = array( __( 'Insight', 'personalized-city-tour' ), $i );
		}
		$sheets[ __( 'Summary', 'personalized-city-tour' ) ] = $rows;
		foreach ( $r['tables'] as $t ) {
			$sheets[ (string) ( $t['title'] ?? 'Table' ) ] = array_merge( array( (array) ( $t['columns'] ?? array() ) ), (array) ( $t['rows'] ?? array() ) );
		}
		foreach ( $r['charts'] as $c ) {
			$rows = array( array( __( 'Label', 'personalized-city-tour' ), __( 'Value', 'personalized-city-tour' ) ) );
			foreach ( (array) ( $c['labels'] ?? array() ) as $i => $label ) {
				$rows[] = array( $label, $c['values'][ $i ] ?? '' );
			}
			$sheets[ (string) ( $c['title'] ?? 'Chart' ) ] = $rows;
		}

		$files      = array();
		$sheet_tags = '';
		$rels       = '';
		$overrides  = '';
		$n          = 0;
		$used       = array();
		foreach ( $sheets as $name => $rows ) {
			$n++;
			$clean = preg_replace( '/[\[\]\*\?\/\\\\:]/u', ' ', mb_substr( (string) $name, 0, 28 ) );
			$clean = trim( $clean ) ? trim( $clean ) : 'Sheet';
			while ( isset( $used[ $clean ] ) ) {
				$clean = mb_substr( $clean, 0, 26 ) . ' ' . $n;
			}
			$used[ $clean ] = 1;
			$files[ "xl/worksheets/sheet{$n}.xml" ] = self::sheet_xml( $rows );
			$sheet_tags .= '<sheet name="' . self::x( $clean ) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
			$rels       .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
			$overrides  .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		}
		$files['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . $overrides . '</Types>';
		$files['_rels/.rels']         = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
		$files['xl/workbook.xml']     = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $sheet_tags . '</sheets></workbook>';
		$files['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';
		return self::zip( $files );
	}

	/* ------------------------------------------------------------------ */
	/* PDF (minimal writer, text only)                                      */
	/* ------------------------------------------------------------------ */

	private static function pdf_text( $s ) {
		// Core PDF fonts are Latin-1 only; transliterate what we can, keep digits/punctuation.
		$s = (string) $s;
		if ( function_exists( 'iconv' ) ) {
			$t = @iconv( 'UTF-8', 'ISO-8859-1//TRANSLIT', $s ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false !== $t ) {
				$s = $t;
			} else {
				$s = preg_replace( '/[^\x20-\x7E]/', '?', $s );
			}
		} else {
			$s = preg_replace( '/[^\x20-\x7E]/', '?', $s );
		}
		return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $s );
	}

	private static function wrap( $text, $width ) {
		$out = array();
		foreach ( explode( "\n", (string) $text ) as $para ) {
			foreach ( explode( "\n", wordwrap( $para, $width, "\n", true ) ) as $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	public static function pdf( array $job ) {
		$r     = self::result( $job );
		$lines = array();
		$lines[] = array( 'H', $job['title'] );
		$lines[] = array( 'S', get_bloginfo( 'name' ) . ' - ' . $job['finished'] );
		$lines[] = array( '', '' );
		$lines[] = array( 'B', __( 'Summary', 'personalized-city-tour' ) );
		foreach ( self::wrap( $r['summary'], 95 ) as $l ) {
			$lines[] = array( '', $l );
		}
		if ( $r['insights'] ) {
			$lines[] = array( '', '' );
			$lines[] = array( 'B', __( 'Insights', 'personalized-city-tour' ) );
			foreach ( $r['insights'] as $i ) {
				foreach ( self::wrap( '- ' . $i, 95 ) as $l ) {
					$lines[] = array( '', $l );
				}
			}
		}
		foreach ( array_merge( $r['charts'], $r['tables'] ) as $blk ) {
			$lines[] = array( '', '' );
			$lines[] = array( 'B', (string) ( $blk['title'] ?? '' ) );
			if ( isset( $blk['labels'] ) ) {
				foreach ( (array) $blk['labels'] as $i => $label ) {
					$lines[] = array( '', str_pad( mb_substr( (string) $label, 0, 60 ), 62 ) . ( $blk['values'][ $i ] ?? '' ) );
				}
			} else {
				$lines[] = array( 'B', implode( ' | ', (array) ( $blk['columns'] ?? array() ) ) );
				foreach ( (array) ( $blk['rows'] ?? array() ) as $row ) {
					foreach ( self::wrap( implode( ' | ', (array) $row ), 95 ) as $l ) {
						$lines[] = array( '', $l );
					}
				}
			}
		}
		$lines[] = array( '', '' );
		$lines[] = array( 'S', PCT_AUTHOR . ' - ' . PCT_CREDITS );

		$pages    = array();
		$per_page = 52;
		for ( $i = 0; $i < count( $lines ); $i += $per_page ) {
			$pages[] = array_slice( $lines, $i, $per_page );
		}
		$objects = array();
		$objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objects[] = ''; // pages placeholder
		$objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
		$page_ids  = array();
		foreach ( $pages as $page ) {
			$y      = 800;
			$stream = 'BT ';
			foreach ( $page as $ln ) {
				list( $style, $text ) = $ln;
				$font = 'B' === $style || 'H' === $style ? '/F2' : '/F1';
				$size = 'H' === $style ? 18 : ( 'S' === $style ? 9 : 10.5 );
				$stream .= sprintf( '%s %s Tf 1 0 0 1 40 %d Tm (%s) Tj ', $font, $size, $y, self::pdf_text( $text ) );
				$y      -= 'H' === $style ? 24 : 14;
			}
			$stream   .= 'ET';
			$objects[] = '<< /Length ' . strlen( $stream ) . " >>\nstream\n" . $stream . "\nendstream";
			$content   = count( $objects );
			$objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents ' . $content . ' 0 R /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> >>';
			$page_ids[] = count( $objects ) . ' 0 R';
		}
		$objects[1] = '<< /Type /Pages /Kids [' . implode( ' ', $page_ids ) . '] /Count ' . count( $page_ids ) . ' >>';

		$pdf     = "%PDF-1.4\n";
		$offsets = array();
		foreach ( $objects as $i => $obj ) {
			$offsets[] = strlen( $pdf );
			$pdf      .= ( $i + 1 ) . " 0 obj\n" . $obj . "\nendobj\n";
		}
		$xref = strlen( $pdf );
		$pdf .= "xref\n0 " . ( count( $objects ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $offsets as $o ) {
			$pdf .= sprintf( "%010d 00000 n \n", $o );
		}
		$pdf .= 'trailer << /Size ' . ( count( $objects ) + 1 ) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
		return $pdf;
	}
}
