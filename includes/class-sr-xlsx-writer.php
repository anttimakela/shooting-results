<?php
/**
 * Minimal, dependency-free .xlsx writer.
 *
 * We deliberately don't pull in PhpSpreadsheet (a large Composer
 * dependency) for what is just "rows of numbers with a bold header" —
 * this hand-rolls the handful of OOXML parts a spreadsheet needs
 * (workbook, one worksheet per round + a summary, minimal styles) and
 * zips them with ZipArchive, which ships with PHP.
 *
 * @package ShootingResults
 */

defined( 'ABSPATH' ) || exit;

class SR_Xlsx_Writer {

	/** @var array<int, array{name: string, rows: array<int, array<int, mixed>>}> */
	private $sheets = array();

	/**
	 * @param string                        $name First 31 chars are used — Excel's sheet-name limit.
	 * @param array<int, array<int, mixed>> $rows Array of rows; each row is an array of cell values.
	 *                                             Row 0 is treated as the header (rendered bold).
	 *                                             A cell value of null is left blank.
	 */
	public function add_sheet( $name, array $rows ) {
		$this->sheets[] = array(
			'name' => mb_substr( $this->sanitize_sheet_name( $name ), 0, 31 ),
			'rows' => $rows,
		);
	}

	private function sanitize_sheet_name( $name ) {
		return str_replace( array( '\\', '/', '?', '*', '[', ']', ':' ), ' ', $name );
	}

	/** Builds the .xlsx and returns the raw file bytes. */
	public function build() {
		$tmp_path = wp_tempnam( 'sr-xlsx' );

		$zip = new ZipArchive();
		$zip->open( $tmp_path, ZipArchive::OVERWRITE );

		$zip->addFromString( '[Content_Types].xml', $this->content_types_xml() );
		$zip->addFromString( '_rels/.rels', $this->root_rels_xml() );
		$zip->addFromString( 'xl/workbook.xml', $this->workbook_xml() );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', $this->workbook_rels_xml() );
		$zip->addFromString( 'xl/styles.xml', $this->styles_xml() );

		foreach ( $this->sheets as $i => $sheet ) {
			$zip->addFromString( 'xl/worksheets/sheet' . ( $i + 1 ) . '.xml', $this->sheet_xml( $sheet['rows'] ) );
		}

		$zip->close();

		$bytes = file_get_contents( $tmp_path );
		wp_delete_file( $tmp_path );

		return $bytes;
	}

	private function content_types_xml() {
		$overrides = '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
		$overrides .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
		foreach ( $this->sheets as $i => $sheet ) {
			$overrides .= '<Override PartName="/xl/worksheets/sheet' . ( $i + 1 ) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		}
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
			'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
			'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
			'<Default Extension="xml" ContentType="application/xml"/>' .
			$overrides .
			'</Types>';
	}

	private function root_rels_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
			'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
			'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
			'</Relationships>';
	}

	private function workbook_xml() {
		$sheets_xml = '';
		foreach ( $this->sheets as $i => $sheet ) {
			$id = $i + 1;
			$sheets_xml .= '<sheet name="' . $this->xml_escape( $sheet['name'] ) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
		}
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
			'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
			'<sheets>' . $sheets_xml . '</sheets>' .
			'</workbook>';
	}

	private function workbook_rels_xml() {
		$rels = '';
		foreach ( $this->sheets as $i => $sheet ) {
			$id = $i + 1;
			$rels .= '<Relationship Id="rId' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $id . '.xml"/>';
		}
		$styles_id = count( $this->sheets ) + 1;
		$rels     .= '<Relationship Id="rId' . $styles_id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
			'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';
	}

	private function styles_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
			'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
			'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>' .
			'<fills count="1"><fill><patternFill patternType="none"/></fill></fills>' .
			'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>' .
			'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
			'<cellXfs count="2">' .
			'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' .
			'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>' .
			'</cellXfs>' .
			'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>' .
			'</styleSheet>';
	}

	private function sheet_xml( array $rows ) {
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
			'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

		foreach ( $rows as $row_index => $row ) {
			$r          = $row_index + 1;
			$style      = 0 === $row_index ? ' s="1"' : '';
			$xml       .= '<row r="' . $r . '">';
			foreach ( $row as $col_index => $value ) {
				if ( null === $value || '' === $value ) {
					continue;
				}
				$ref = $this->column_letter( $col_index + 1 ) . $r;
				if ( is_numeric( $value ) ) {
					$xml .= '<c r="' . $ref . '"' . $style . '><v>' . $this->xml_escape( (string) $value ) . '</v></c>';
				} else {
					$xml .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . $this->xml_escape( $this->neutralize_formula( (string) $value ) ) . '</t></is></c>';
				}
			}
			$xml .= '</row>';
		}

		$xml .= '</sheetData></worksheet>';
		return $xml;
	}

	/**
	 * Defense-in-depth against spreadsheet "formula injection": a shooter
	 * name is free text a site visitor with the page password can set,
	 * and if it started with =, +, -, or @, some spreadsheet software
	 * treats it as a live formula when the file is later opened. Cells
	 * are already written with an explicit inlineStr type (not a bare/
	 * general type), which itself should stop most formula evaluation —
	 * this is a cheap second layer on top of that, standard practice for
	 * any spreadsheet export of user-supplied text (the CSV-injection
	 * mitigation OWASP recommends, applied here too).
	 */
	private function neutralize_formula( $text ) {
		if ( isset( $text[0] ) && false !== strpos( "=+-@\t\r", $text[0] ) ) {
			return "'" . $text;
		}
		return $text;
	}

	private function column_letter( $index ) {
		$letter = '';
		while ( $index > 0 ) {
			$mod    = ( $index - 1 ) % 26;
			$letter = chr( 65 + $mod ) . $letter;
			$index  = (int) ( ( $index - $mod ) / 26 );
		}
		return $letter;
	}

	private function xml_escape( $text ) {
		return htmlspecialchars( $text, ENT_XML1 | ENT_COMPAT, 'UTF-8' );
	}
}
