<?php

namespace spaceonfire\Restify\Formatters;

use Bitrix\Main\Event;
use CFile;

class FileFormatter implements IFormatter {

	/**
	 * Get bitrix file or files
	 * @param string|int|[string|int] $fileStuff
	 * @return array
	 */
	public static function format($fileStuff) {
		$fileResults = is_array( $fileStuff )
			? FileFormatter::formatFiles( $fileStuff )
			: FileFormatter::formatFile( $fileStuff )
		;

		return $fileResults;
	}

	/**
	 * Get bitrix file
	 * @param string|int $fileId
	 * @return array
	 */
	private static function formatFile($fileId) {
		$rawFile = CFile::GetFileArray($fileId);

		$selectFields = [
			'ID',
			'SRC',
			'HEIGHT',
			'WIDTH',
			'FILE_SIZE',
			'CONTENT_TYPE',
			'ORIGINAL_NAME',
			'DESCRIPTION'
		];

		$file = [];
		foreach ($selectFields as $field) {
			$file[$field] = $rawFile[$field];
		}

		// TODO: make full path optional
		// $file['SRC'] = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . $file['SRC'];

		global $SPACEONFIRE_RESTIFY;
		$event = new Event($SPACEONFIRE_RESTIFY->getId(), 'OnFileFormatter', [
			'data' => &$file,
		]);
		$event->send();

		$file = is_array( $file )
			? (
				! is_null( $file[ 'ID' ] )
					? $file

					// Property's 'NAME" field
					: $fileId
			)
			: $fileId
		;

		return $file;
	}

	/**
	 * Get bitrix files
	 * @param [string|int] $files
	 * @return [array]
	 */
	private static function formatFiles($files) {
		foreach( $files as $fileId ){
			$fileResult = FileFormatter::formatFile( $fileId );
			$fileResults[] = $fileResult;
		}

		return $fileResults;
	}
}
