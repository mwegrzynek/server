<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Andreas Fischer <bantu@owncloud.com>
 * @author Hendrik Leppelsack <hendrik@leppelsack.de>
 * @author Jens-Christian Fischer <jens-christian.fischer@switch.ch>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Magnus Walbeck <mw@mwalbeck.org>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <robin@icewind.nl>
 * @author Robin McCorkell <robin@mccorkell.me.uk>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 * @author Thomas Tanghus <thomas@tanghus.net>
 * @author Vincent Petry <pvince81@owncloud.com>
 * @author Xheni Myrtaj <myrtajxheni@gmail.com>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OC\Files\Type;

use OCP\Files\IMimeTypeDetector;
use OCP\ILogger;
use OCP\IURLGenerator;

/**
 * Class Detection
 *
 * Mimetype detection
 *
 * @package OC\Files\Type
 */
class Detection implements IMimeTypeDetector {

	private const CUSTOM_MIMETYPEMAPPING = 'mimetypemapping.json';
	private const CUSTOM_MIMETYPEALIASES = 'mimetypealiases.json';

	protected $mimetypes = [];
	protected $secureMimeTypes = [];

	protected $mimetypeIcons = [];
	/** @var string[] */
	protected $mimeTypeAlias = [];

	/** @var IURLGenerator */
	private $urlGenerator;

	/** @var ILogger */
	private $logger;

	/** @var string */
	private $customConfigDir;

	/** @var string */
	private $defaultConfigDir;

	/**
	 * @param IURLGenerator $urlGenerator
	 * @param ILogger $logger
	 * @param string $customConfigDir
	 * @param string $defaultConfigDir
	 */
	public function __construct(IURLGenerator $urlGenerator,
								ILogger $logger,
								$customConfigDir,
								$defaultConfigDir) {
		$this->urlGenerator = $urlGenerator;
		$this->logger = $logger;
		$this->customConfigDir = $customConfigDir;
		$this->defaultConfigDir = $defaultConfigDir;
	}

	/**
	 * Add an extension -> mimetype mapping
	 *
	 * $mimetype is the assumed correct mime type
	 * The optional $secureMimeType is an alternative to send to send
	 * to avoid potential XSS.
	 *
	 * @param string $extension
	 * @param string $mimetype
	 * @param string|null $secureMimeType
	 */
	public function registerType($extension,
								 $mimetype,
								 $secureMimeType = null) {
		$this->mimetypes[$extension] = array($mimetype, $secureMimeType);
		$this->secureMimeTypes[$mimetype] = $secureMimeType ?: $mimetype;
	}

	/**
	 * Add an array of extension -> mimetype mappings
	 *
	 * The mimetype value is in itself an array where the first index is
	 * the assumed correct mimetype and the second is either a secure alternative
	 * or null if the correct is considered secure.
	 *
	 * @param array $types
	 */
	public function registerTypeArray($types) {
		$this->mimetypes = array_merge($this->mimetypes, $types);

		// Update the alternative mimetypes to avoid having to look them up each time.
		foreach ($this->mimetypes as $mimeType) {
			$this->secureMimeTypes[$mimeType[0]] = isset($mimeType[1]) ? $mimeType[1]: $mimeType[0];
		}
	}

	private function loadCustomDefinitions(string $fileName, array $definitions): array {
		if (file_exists($this->customConfigDir . '/' . $fileName)) {
			$custom = json_decode(file_get_contents($this->customConfigDir . '/' . $fileName), true);
			if (json_last_error() === JSON_ERROR_NONE) {
				$definitions = array_merge($definitions, $custom);
			} else {
				$this->logger->warning('Failed to parse ' . $fileName . ': ' . json_last_error_msg());
			}
		}
		return $definitions;
	}

	/**
	 * Add the mimetype aliases if they are not yet present
	 */
	private function loadAliases() {
		if (!empty($this->mimeTypeAlias)) {
			return;
		}

		$this->mimeTypeAlias = json_decode(file_get_contents($this->defaultConfigDir . '/mimetypealiases.dist.json'), true);
		$this->mimeTypeAlias = $this->loadCustomDefinitions(self::CUSTOM_MIMETYPEALIASES, $this->mimeTypeAlias);
	}

	/**
	 * @return string[]
	 */
	public function getAllAliases() {
		$this->loadAliases();
		return $this->mimeTypeAlias;
	}

	public function getOnlyDefaultAliases() {
		$this->loadMappings();
		$this->mimeTypeAlias = json_decode(file_get_contents($this->defaultConfigDir . '/mimetypealiases.dist.json'), true);
		return $this->mimeTypeAlias;
	}

	/**
	 * Add mimetype mappings if they are not yet present
	 */
	private function loadMappings() {
		if (!empty($this->mimetypes)) {
			return;
		}

		$mimetypeMapping = json_decode(file_get_contents($this->defaultConfigDir . '/mimetypemapping.dist.json'), true);
		$mimetypeMapping = $this->loadCustomDefinitions(self::CUSTOM_MIMETYPEMAPPING, $mimetypeMapping);

		$this->registerTypeArray($mimetypeMapping);
	}

	/**
	 * @return array
	 */
	public function getAllMappings() {
		$this->loadMappings();
		return $this->mimetypes;
	}

	/**
	 * detect mimetype only based on filename, content of file is not used
	 *
	 * @param string $path
	 * @return string
	 */
	public function detectPath($path) {
		$this->loadMappings();

		$fileName = basename($path);

		// remove leading dot on hidden files with a file extension
		$fileName = ltrim($fileName, '.');

		// note: leading dot doesn't qualify as extension
		if (strpos($fileName, '.') > 0) {

			// remove versioning extension: name.v1508946057 and transfer extension: name.ocTransferId2057600214.part
			$fileName = preg_replace('!((\.v\d+)|((.ocTransferId\d+)?.part))$!', '', $fileName);

			//try to guess the type by the file extension
			$extension = strtolower(strrchr($fileName, '.'));
			$extension = substr($extension, 1); //remove leading .
			return (isset($this->mimetypes[$extension]) && isset($this->mimetypes[$extension][0]))
				? $this->mimetypes[$extension][0]
				: 'application/octet-stream';
		} else {
			return 'application/octet-stream';
		}
	}

	/**
	 * detect mimetype based on both filename and content
	 *
	 * @param string $path
	 * @return string
	 */
	public function detect($path) {
		$this->loadMappings();

		if (@is_dir($path)) {
			// directories are easy
			return "httpd/unix-directory";
		}

		$mimeType = $this->detectPath($path);

		if ($mimeType === 'application/octet-stream' and function_exists('finfo_open')
			and function_exists('finfo_file') and $finfo = finfo_open(FILEINFO_MIME)
		) {
			$info = @strtolower(finfo_file($finfo, $path));
			finfo_close($finfo);
			if ($info) {
				$mimeType = strpos($info, ';') !== false ? substr($info, 0, strpos($info, ';')) : $info;
				return empty($mimeType) ? 'application/octet-stream' : $mimeType;
			}

		}
		$isWrapped = (strpos($path, '://') !== false) and (substr($path, 0, 7) === 'file://');
		if (!$isWrapped and $mimeType === 'application/octet-stream' && function_exists("mime_content_type")) {
			// use mime magic extension if available
			$mimeType = mime_content_type($path);
		}
		if (!$isWrapped and $mimeType === 'application/octet-stream' && \OC_Helper::canExecute("file")) {
			// it looks like we have a 'file' command,
			// lets see if it does have mime support
			$path = escapeshellarg($path);
			$fp = popen("file -b --mime-type $path 2>/dev/null", "r");
			$reply = fgets($fp);
			pclose($fp);

			//trim the newline
			$mimeType = trim($reply);

			if (empty($mimeType)) {
				$mimeType = 'application/octet-stream';
			}

		}
		return $mimeType;
	}

	/**
	 * detect mimetype based on the content of a string
	 *
	 * @param string $data
	 * @return string
	 */
	public function detectString($data) {
		if (function_exists('finfo_open') and function_exists('finfo_file')) {
			$finfo = finfo_open(FILEINFO_MIME);
			$info = finfo_buffer($finfo, $data);
			return strpos($info, ';') !== false ? substr($info, 0, strpos($info, ';')) : $info;
		} else {
			$tmpFile = \OC::$server->getTempManager()->getTemporaryFile();
			$fh = fopen($tmpFile, 'wb');
			fwrite($fh, $data, 8024);
			fclose($fh);
			$mime = $this->detect($tmpFile);
			unset($tmpFile);
			return $mime;
		}
	}

	/**
	 * Get a secure mimetype that won't expose potential XSS.
	 *
	 * @param string $mimeType
	 * @return string
	 */
	public function getSecureMimeType($mimeType) {
		$this->loadMappings();

		return isset($this->secureMimeTypes[$mimeType])
			? $this->secureMimeTypes[$mimeType]
			: 'application/octet-stream';
	}

	/**
	 * Get path to the icon of a file type
	 * @param string $mimetype the MIME type
	 * @return string the url
	 */
	public function mimeTypeIcon($mimetype) {
		$this->loadAliases();

		while (isset($this->mimeTypeAlias[$mimetype])) {
			$mimetype = $this->mimeTypeAlias[$mimetype];
		}
		if (isset($this->mimetypeIcons[$mimetype])) {
			return $this->mimetypeIcons[$mimetype];
		}

		// Replace slash and backslash with a minus
		$icon = str_replace('/', '-', $mimetype);
		$icon = str_replace('\\', '-', $icon);

		// Is it a dir?
		if ($mimetype === 'dir') {
			$this->mimetypeIcons[$mimetype] = $this->urlGenerator->imagePath('core', 'filetypes/folder.svg');
			return $this->mimetypeIcons[$mimetype];
		}
		if ($mimetype === 'dir-shared') {
			$this->mimetypeIcons[$mimetype] = $this->urlGenerator->imagePath('core', 'filetypes/folder-shared.svg');
			return $this->mimetypeIcons[$mimetype];
		}
		if ($mimetype === 'dir-external') {
			$this->mimetypeIcons[$mimetype] = $this->urlGenerator->imagePath('core', 'filetypes/folder-external.svg');
			return $this->mimetypeIcons[$mimetype];
		}

		// Icon exists?
		try {
			$this->mimetypeIcons[$mimetype] = $this->urlGenerator->imagePath('core', 'filetypes/' . $icon . '.svg');
			return $this->mimetypeIcons[$mimetype];
		} catch (\RuntimeException $e) {
			// Specified image not found
		}

		// Try only the first part of the filetype
		$mimePart = substr($icon, 0, strpos($icon, '-'));
		try {
			$this->mimetypeIcons[$mimetype] = $this->urlGenerator->imagePath('core', 'filetypes/' . $mimePart . '.svg');
			return $this->mimetypeIcons[$mimetype];
		} catch (\RuntimeException $e) {
			// Image for the first part of the mimetype not found
		}

		$this->mimetypeIcons[$mimetype] = $this->urlGenerator->imagePath('core', 'filetypes/file.svg');
		return $this->mimetypeIcons[$mimetype];
	}
}
