<?php
/**
 * Filesystem family, confined to the WordPress root. All paths are relative
 * to the root ("wp-content/plugins/foo/foo.php"); "." is the root itself.
 *
 * fs-list, fs-read, fs-search, fs-write, fs-patch, fs-mkdir, fs-copy, fs-move,
 * fs-delete, fs-chmod, fs-zip, fs-unzip.
 *
 * @package AIGodmode
 */

namespace AIGodmode\Abilities;

use AIGodmode\Paths;
use AIGodmode\Scrub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Fs_List extends Base {
	public static function name(): string {
		return 'godmode/fs-list';
	}
	public static function definition(): array {
		return self::make(
			'filesystem',
			'List Directory',
			'List a directory under the WordPress root with type, size, permissions and modified time. Optional recursion with depth and entry caps.',
			self::obj( array( 'path' => self::str( 1024 ), 'recursive' => self::bool( false ), 'max_depth' => self::int( 1, 10, 3 ), 'limit' => self::int( 1, 5000, 500 ), 'include_hidden' => self::bool( true ) ) ),
			self::obj( array( 'path' => self::str(), 'count' => array( 'type' => 'integer' ), 'truncated' => self::bool(), 'entries' => self::list_of( self::map() ) ), array( 'path', 'count', 'truncated', 'entries' ) ),
			false
		);
	}
	public function execute( $input ) {
		$abs = Paths::resolve( (string) ( $input['path'] ?? '.' ) );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( ! is_dir( $abs ) ) {
			return self::err( 'godmode_fs_not_dir', Paths::display( $abs ) . ' is not a directory.' );
		}
		$limit     = (int) ( $input['limit'] ?? 500 );
		$max_depth = ! empty( $input['recursive'] ) ? (int) ( $input['max_depth'] ?? 3 ) : 1;
		$hidden    = ! isset( $input['include_hidden'] ) || ! empty( $input['include_hidden'] );
		$entries   = array();
		$truncated = false;
		$walk      = function ( string $dir, int $depth ) use ( &$walk, &$entries, &$truncated, $limit, $max_depth, $hidden ) {
			$names = @scandir( $dir );
			if ( ! is_array( $names ) ) {
				return;
			}
			foreach ( $names as $n ) {
				if ( '.' === $n || '..' === $n || ( ! $hidden && 0 === strpos( $n, '.' ) ) ) {
					continue;
				}
				if ( count( $entries ) >= $limit ) {
					$truncated = true;
					return;
				}
				$p    = $dir . '/' . $n;
				$link = is_link( $p );
				$type = $link ? 'link' : ( is_dir( $p ) ? 'dir' : 'file' );
				$entries[] = array(
					'path'     => Paths::display( $p ),
					'type'     => $type,
					'bytes'    => 'file' === $type ? (int) @filesize( $p ) : null,
					'perms'    => substr( sprintf( '%o', @fileperms( $p ) ), -4 ),
					'modified' => gmdate( 'c', (int) @filemtime( $p ) ),
				);
				if ( 'dir' === $type && $depth < $max_depth ) {
					$walk( $p, $depth + 1 );
				}
			}
		};
		$walk( rtrim( $abs, '/' ), 1 );
		return array( 'path' => Paths::display( $abs ), 'count' => count( $entries ), 'truncated' => $truncated, 'entries' => $entries );
	}
}

final class Fs_Read extends Base {
	public const MAX_BYTES = 1048576;
	public static function name(): string {
		return 'godmode/fs-read';
	}
	public static function definition(): array {
		return self::make(
			'filesystem',
			'Read File',
			'Read a file under the WordPress root. Secrets are scrubbed from the whole file first (config passwords, salts, keys), then offset and length select a window of that scrubbed text, base64-encoded when encoding is base64. Offsets are positions in the scrubbed text; bytes is the raw size on disk. Capped at 1 MB per call with offset for more.',
			self::obj( array( 'path' => self::str( 1024, 1 ), 'encoding' => array( 'type' => 'string', 'enum' => array( 'text', 'base64' ), 'default' => 'text' ), 'offset' => self::int( 0, 0, 0 ), 'length' => self::int( 1, 1048576, 1048576 ) ), array( 'path' ) ),
			self::obj( array( 'path' => self::str(), 'bytes' => array( 'type' => 'integer' ), 'offset' => array( 'type' => 'integer' ), 'returned' => array( 'type' => 'integer' ), 'truncated' => self::bool(), 'encoding' => self::str(), 'content' => self::str(), 'scrubbed' => self::bool() ), array( 'path', 'bytes', 'returned', 'truncated', 'encoding', 'content' ) ),
			false
		);
	}
	public function execute( $input ) {
		$abs = Paths::resolve( (string) $input['path'] );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( ! is_file( $abs ) ) {
			return self::err( 'godmode_fs_not_file', Paths::display( $abs ) . ' is not a file.' );
		}
		if ( ! is_readable( $abs ) ) {
			return self::err( 'godmode_fs_unreadable', Paths::display( $abs ) . ' is not readable by the web server user.', 403 );
		}
		$size   = (int) filesize( $abs );
		$offset = (int) ( $input['offset'] ?? 0 );
		$length = min( self::MAX_BYTES, (int) ( $input['length'] ?? self::MAX_BYTES ) );
		$enc    = (string) ( $input['encoding'] ?? 'text' );
		// Scrub the text as a whole, never a fragment: a read starting mid-line
		// or a base64 read would slip a secret past the patterns. Read one
		// extra window beyond the request so no line straddles the read
		// boundary, scrub, then slice. Offsets are positions in the scrubbed
		// text, and only the slice is encoded.
		$window   = (string) file_get_contents( $abs, false, null, 0, $offset + $length + self::MAX_BYTES );
		$text     = Scrub::text_checked( $window );
		if ( null === $text ) {
			return self::err( 'godmode_fs_scrub_failed', Paths::display( $abs ) . ' could not be scrubbed (the pattern engine hit its limit), so it is not returned. Use run-php if you need its raw bytes.', 422 );
		}
		$chunk    = $offset >= strlen( $text ) ? '' : substr( $text, $offset, $length );
		$more     = ( $offset + strlen( $chunk ) ) < strlen( $text ) || strlen( $window ) < $size;
		$scrubbed = false !== strpos( $chunk, Scrub::MARK );
		$content  = 'base64' === $enc ? base64_encode( $chunk ) : $chunk;
		return array( 'path' => Paths::display( $abs ), 'bytes' => $size, 'offset' => $offset, 'returned' => strlen( $chunk ), 'truncated' => $more, 'encoding' => $enc, 'content' => $content, 'scrubbed' => $scrubbed );
	}
}

final class Fs_Search extends Base {
	public static function name(): string {
		return 'godmode/fs-search';
	}
	public static function definition(): array {
		return self::make(
			'filesystem',
			'Search Files',
			'Search file contents under a directory for a substring or regex. Returns matching lines (scrubbed) with file and line number. Skips files over 2 MB and binary-looking files; files that cannot be scrubbed are listed in unscrubbable, never searched raw.',
			self::obj( array( 'path' => self::str( 1024 ), 'query' => self::str( 500, 1 ), 'regex' => self::bool( false ), 'extensions' => self::list_of( self::str( 16 ) ), 'max_results' => self::int( 1, 1000, 200 ), 'max_files' => self::int( 1, 20000, 5000 ) ), array( 'query' ) ),
			self::obj( array( 'matches' => self::list_of( self::map() ), 'count' => array( 'type' => 'integer' ), 'files_scanned' => array( 'type' => 'integer' ), 'truncated' => self::bool(), 'unscrubbable' => self::list_of( self::str() ) ), array( 'matches', 'count', 'files_scanned', 'truncated' ) ),
			false
		);
	}
	public function execute( $input ) {
		$unscrubbable = array();
		$abs = Paths::resolve( (string) ( $input['path'] ?? '.' ) );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		$query = (string) $input['query'];
		$regex = ! empty( $input['regex'] );
		if ( $regex && false === @preg_match( '/' . str_replace( '/', '\/', $query ) . '/', '' ) ) {
			return self::err( 'godmode_fs_bad_regex', 'The regex does not compile.' );
		}
		$exts      = array_map( 'strtolower', (array) ( $input['extensions'] ?? array() ) );
		$max_res   = (int) ( $input['max_results'] ?? 200 );
		$max_files = (int) ( $input['max_files'] ?? 5000 );
		$matches   = array();
		$scanned   = 0;
		$truncated = false;
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $abs, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD );
		foreach ( $it as $file ) {
			if ( $scanned >= $max_files || count( $matches ) >= $max_res ) {
				$truncated = true;
				break;
			}
			if ( ! $file->isFile() || $file->getSize() > 2097152 ) {
				continue;
			}
			if ( $exts && ! in_array( strtolower( $file->getExtension() ), $exts, true ) ) {
				continue;
			}
			$scanned++;
			$text = @file_get_contents( $file->getPathname() );
			if ( false === $text || false !== strpos( substr( $text, 0, 8000 ), "\0" ) ) {
				continue;
			}
			if ( ! $regex && false === stripos( $text, $query ) ) {
				continue;
			}
			// Scrub the whole file, not the 300-character fragment: a value cut
			// short has no closing quote for the patterns to find.
			$text = Scrub::text_checked( $text );
			if ( null === $text ) {
				$unscrubbable[] = Paths::display( $file->getPathname() );
				continue;
			}
			foreach ( explode( "\n", $text ) as $i => $line ) {
				$hit = $regex ? (bool) preg_match( '/' . str_replace( '/', '\/', $query ) . '/', $line ) : false !== stripos( $line, $query );
				if ( $hit ) {
					$matches[] = array( 'path' => Paths::display( $file->getPathname() ), 'line' => $i + 1, 'text' => substr( trim( $line ), 0, 300 ) );
					if ( count( $matches ) >= $max_res ) {
						break;
					}
				}
			}
		}
		return array( 'matches' => $matches, 'count' => count( $matches ), 'files_scanned' => $scanned, 'truncated' => $truncated, 'unscrubbable' => $unscrubbable );
	}
}

final class Fs_Write extends Base {
	public static function name(): string {
		return 'godmode/fs-write';
	}
	public static function definition(): array {
		return self::make(
			'filesystem',
			'Write File',
			'Create or replace a file under the WordPress root atomically (temp file then rename). An existing file is backed up under an unguessable name into uploads/godmode-backups (web-denied by .htaccess on Apache and LiteSpeed; other servers get a warning) unless backup is false. Refuses wp-config.php, the root .htaccess, .env files, .git trees, AI Godmode itself, and symlinks. Content may be base64.',
			self::obj( array( 'path' => self::str( 1024, 1 ), 'content' => self::str( 5242880 ), 'encoding' => array( 'type' => 'string', 'enum' => array( 'text', 'base64' ), 'default' => 'text' ), 'backup' => self::bool( true ), 'create_dirs' => self::bool( false ), 'append' => self::bool( false ) ) + self::ack(), array( 'path', 'content' ) ),
			self::obj( array( 'path' => self::str(), 'bytes' => array( 'type' => 'integer' ), 'existed' => self::bool(), 'backup' => self::nullable( self::str() ), 'warning' => self::nullable( self::str() ) ), array( 'path', 'bytes', 'existed' ) ),
			true
		);
	}
	public function execute( $input ) {
		$rel = (string) $input['path'];
		$abs = ! empty( $input['create_dirs'] ) ? Paths::resolve_new( $rel ) : Paths::resolve( $rel, false );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( Paths::is_protected_file( $abs ) ) {
			return self::err( 'godmode_fs_protected', Paths::display( $abs ) . ' is a protected file and cannot be written through AI Godmode.', 403 );
		}
		if ( is_link( $abs ) ) {
			return self::err( 'godmode_fs_symlink', Paths::display( $abs ) . ' is a symlink; writing through it is refused.', 403 );
		}
		if ( is_dir( $abs ) ) {
			return self::err( 'godmode_fs_is_dir', Paths::display( $abs ) . ' is a directory.' );
		}
		if ( ! empty( $input['create_dirs'] ) && ! is_dir( dirname( $abs ) ) && ! wp_mkdir_p( dirname( $abs ) ) ) {
			return self::err( 'godmode_fs_write_failed', 'Could not create parent directories.', 500 );
		}
		$content = (string) $input['content'];
		if ( 'base64' === ( $input['encoding'] ?? 'text' ) ) {
			$decoded = base64_decode( $content, true );
			if ( false === $decoded ) {
				return self::err( 'godmode_fs_bad_base64', 'Content is not valid base64.' );
			}
			$content = $decoded;
		}
		$existed = is_file( $abs );
		$backup  = null;
		if ( $existed && ! empty( $input['append'] ) ) {
			$content = (string) file_get_contents( $abs ) . $content;
		}
		// fs-read hands out scrubbed text. Writing it back would replace the
		// real secrets with the marker, so a write that adds markers is refused.
		$marks_before = $existed ? substr_count( (string) file_get_contents( $abs ), Scrub::MARK ) : 0;
		if ( substr_count( $content, Scrub::MARK ) > $marks_before ) {
			return self::err( 'godmode_write_redacted', 'The content carries redaction markers from a scrubbed read. Writing it would replace real secrets with the marker. Supply the real values, or edit the file with run-php.' );
		}
		if ( $existed && ( ! isset( $input['backup'] ) || ! empty( $input['backup'] ) ) ) {
			// Never beside the original: a backup of wp-config.php written next
			// to it would be served as plain text by the web server.
			$bdir = Paths::protected_dir( 'backups' );
			if ( is_wp_error( $bdir ) ) {
				return $bdir;
			}
			// Unguessable name: where .htaccess is ignored the name is the only guard.
			$backup = $bdir . '/' . str_replace( '/', '__', Paths::display( $abs ) ) . '.' . gmdate( 'Ymd-His' ) . '.' . Paths::unguessable() . '.bak';
			if ( ! @copy( $abs, $backup ) ) {
				return self::err( 'godmode_fs_backup_failed', 'Could not write the backup copy; nothing was changed.', 500 );
			}
		}
		$r = Paths::atomic_write( $abs, $content );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( function_exists( 'opcache_invalidate' ) && 'php' === strtolower( pathinfo( $abs, PATHINFO_EXTENSION ) ) ) {
			@opcache_invalidate( $abs, true );
		}
		return array( 'path' => Paths::display( $abs ), 'bytes' => strlen( $content ), 'existed' => $existed, 'backup' => $backup ? Paths::display( $backup ) : null, 'warning' => $backup ? Paths::htaccess_warning() : null );
	}
}

final class Fs_Patch extends Base {
	public static function name(): string {
		return 'godmode/fs-patch';
	}
	public static function definition(): array {
		return self::make(
			'filesystem',
			'Patch File',
			'Replace one exact text passage inside a file without sending the whole file back: find must occur exactly expect times (default 1) or nothing is written. Same protections and backup as fs-write; the write is atomic. Returns the number of replacements and the byte counts before and after. Use fs-read to see the passage first; fs-search finds the file.',
			self::obj( array( 'path' => self::str( 1024, 1 ), 'find' => self::str( 1048576, 1 ), 'replace' => self::str( 1048576 ), 'expect' => self::int( 1, 10000, 1 ), 'backup' => self::bool( true ) ) + self::ack(), array( 'path', 'find', 'replace' ) ),
			self::obj( array( 'path' => self::str(), 'replacements' => array( 'type' => 'integer' ), 'bytes_before' => array( 'type' => 'integer' ), 'bytes_after' => array( 'type' => 'integer' ), 'backup' => self::nullable( self::str() ), 'warning' => self::nullable( self::str() ) ), array( 'path', 'replacements', 'bytes_before', 'bytes_after' ) ),
			true
		);
	}
	public function execute( $input ) {
		$abs = Paths::resolve( (string) $input['path'], true );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( Paths::is_protected_file( $abs ) ) {
			return self::err( 'godmode_fs_protected', Paths::display( $abs ) . ' is a protected file and cannot be written through AI Godmode.', 403 );
		}
		if ( is_link( $abs ) ) {
			return self::err( 'godmode_fs_symlink', Paths::display( $abs ) . ' is a symlink; writing through it is refused.', 403 );
		}
		if ( ! is_file( $abs ) ) {
			return self::err( 'godmode_fs_not_file', Paths::display( $abs ) . ' is not a file.', 404 );
		}
		$find    = (string) $input['find'];
		$replace = (string) $input['replace'];
		$expect  = (int) ( $input['expect'] ?? 1 );
		if ( false !== strpos( $replace, Scrub::MARK ) ) {
			return self::err( 'godmode_write_redacted', 'The replacement carries a redaction marker from a scrubbed read. Supply the real value.' );
		}
		$before = (string) file_get_contents( $abs );
		$count  = substr_count( $before, $find );
		if ( 0 === $count ) {
			return self::err( 'godmode_patch_no_match', 'The find text does not occur in ' . Paths::display( $abs ) . '. Nothing was written. Note that fs-read scrubs secrets, so a passage containing one cannot be matched verbatim.', 409 );
		}
		if ( $count !== $expect ) {
			return self::err( 'godmode_patch_count', sprintf( 'The find text occurs %d times in %s, not %d. Nothing was written. Widen the find text until it is unique, or pass expect.', $count, Paths::display( $abs ), $expect ), 409 );
		}
		$after  = str_replace( $find, $replace, $before );
		$backup = null;
		if ( ! isset( $input['backup'] ) || ! empty( $input['backup'] ) ) {
			$bdir = Paths::protected_dir( 'backups' );
			if ( is_wp_error( $bdir ) ) {
				return $bdir;
			}
			$backup = $bdir . '/' . str_replace( '/', '__', Paths::display( $abs ) ) . '.' . gmdate( 'Ymd-His' ) . '.' . Paths::unguessable() . '.bak';
			if ( ! @copy( $abs, $backup ) ) {
				return self::err( 'godmode_fs_backup_failed', 'Could not write the backup copy; nothing was changed.', 500 );
			}
		}
		$r = Paths::atomic_write( $abs, $after );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( function_exists( 'opcache_invalidate' ) && 'php' === strtolower( pathinfo( $abs, PATHINFO_EXTENSION ) ) ) {
			@opcache_invalidate( $abs, true );
		}
		return array( 'path' => Paths::display( $abs ), 'replacements' => $count, 'bytes_before' => strlen( $before ), 'bytes_after' => strlen( $after ), 'backup' => $backup ? Paths::display( $backup ) : null, 'warning' => $backup ? Paths::htaccess_warning() : null );
	}
}

final class Fs_Mkdir extends Base {
	public static function name(): string {
		return 'godmode/fs-mkdir';
	}
	public static function definition(): array {
		return self::make(
			'filesystem',
			'Make Directory',
			'Create a directory (and parents) under the WordPress root.',
			self::obj( array( 'path' => self::str( 1024, 1 ), 'mode' => self::str( 4 ) ) + self::ack(), array( 'path' ) ),
			self::obj( array( 'path' => self::str(), 'created' => self::bool() ), array( 'path', 'created' ) ),
			true
		);
	}
	public function execute( $input ) {
		$abs = Paths::resolve_new( (string) $input['path'] );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( Paths::is_protected_file( $abs ) ) {
			return self::err( 'godmode_fs_protected', Paths::display( $abs ) . ' is a protected path.', 403 );
		}
		if ( is_dir( $abs ) ) {
			return array( 'path' => Paths::display( $abs ), 'created' => false );
		}
		$mode = isset( $input['mode'] ) && preg_match( '/^[0-7]{3,4}$/', (string) $input['mode'] ) ? octdec( (string) $input['mode'] ) : 0755;
		if ( ! wp_mkdir_p( $abs ) ) {
			return self::err( 'godmode_fs_mkdir_failed', 'Could not create ' . Paths::display( $abs ) . '.', 500 );
		}
		// wp_mkdir_p() copies the parent's permissions; apply the requested mode to the leaf.
		Paths::fs()->chmod( $abs, $mode );
		return array( 'path' => Paths::display( $abs ), 'created' => true );
	}
}

final class Fs_Copy extends Base {
	public static function name(): string {
		return 'godmode/fs-copy';
	}
	public static function definition(): array {
		return self::make(
			'filesystem',
			'Copy',
			'Copy a file or directory (recursively) to a new path under the WordPress root. Refuses to overwrite unless overwrite is true, and refuses sources that are or contain whole-secret files (wp-config.php, .env, .htpasswd, private-key .pem, *.key).',
			self::obj( array( 'from' => self::str( 1024, 1 ), 'to' => self::str( 1024, 1 ), 'overwrite' => self::bool( false ) ) + self::ack(), array( 'from', 'to' ) ),
			self::obj( array( 'from' => self::str(), 'to' => self::str(), 'entries' => array( 'type' => 'integer' ) ), array( 'from', 'to', 'entries' ) ),
			true
		);
	}
	public function execute( $input ) {
		$from = Paths::resolve( (string) $input['from'] );
		if ( is_wp_error( $from ) ) {
			return $from;
		}
		$to = Paths::resolve( (string) $input['to'], false );
		if ( is_wp_error( $to ) ) {
			return $to;
		}
		if ( Paths::is_protected_file( $to ) ) {
			return self::err( 'godmode_fs_protected', Paths::display( $to ) . ' is a protected path and cannot be written through AI Godmode.', 403 );
		}
		if ( is_link( $to ) ) {
			return self::err( 'godmode_fs_symlink', Paths::display( $to ) . ' is a symlink; writing through it is refused.', 403 );
		}
		if ( file_exists( $to ) && empty( $input['overwrite'] ) ) {
			return self::err( 'godmode_fs_exists', Paths::display( $to ) . ' already exists. Pass overwrite true to replace it.', 409 );
		}
		if ( is_dir( $from ) && 0 === strpos( rtrim( $to, '/' ) . '/', rtrim( $from, '/' ) . '/' ) ) {
			return self::err( 'godmode_fs_recursive', 'Cannot copy a directory into itself.' );
		}
		$secret = Scrub::sensitive_under( $from );
		if ( $secret ) {
			return self::err( 'godmode_fs_sensitive', 'Refused: the copy would include files whose whole content is a secret (' . implode( ', ', $secret ) . '). A copy cannot be scrubbed and could land where the web server serves it raw.', 403 );
		}
		$r = Paths::copy_r( $from, $to );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return array( 'from' => Paths::display( $from ), 'to' => Paths::display( $to ), 'entries' => (int) $r );
	}
}

final class Fs_Move extends Base {
	public static function name(): string {
		return 'godmode/fs-move';
	}
	public static function definition(): array {
		return self::make(
			'filesystem',
			'Move or Rename',
			'Move or rename a file or directory under the WordPress root. Refuses protected directories (root, wp-admin, wp-includes, wp-content, plugins, AI Godmode itself) and whole-secret files, and refuses to overwrite unless overwrite is true.',
			self::obj( array( 'from' => self::str( 1024, 1 ), 'to' => self::str( 1024, 1 ), 'overwrite' => self::bool( false ) ) + self::ack(), array( 'from', 'to' ) ),
			self::obj( array( 'from' => self::str(), 'to' => self::str(), 'moved' => self::bool() ), array( 'from', 'to', 'moved' ) ),
			true
		);
	}
	public function execute( $input ) {
		$from = Paths::resolve( (string) $input['from'] );
		if ( is_wp_error( $from ) ) {
			return $from;
		}
		if ( Paths::is_protected_dir( $from ) || Paths::is_protected_file( $from ) ) {
			return self::err( 'godmode_fs_protected', Paths::display( $from ) . ' is a protected path.', 403 );
		}
		$secret = Scrub::sensitive_under( $from );
		if ( $secret ) {
			return self::err( 'godmode_fs_sensitive', 'Refused: the move would carry files whose whole content is a secret (' . implode( ', ', $secret ) . ') to a new location, possibly one the web server serves raw.', 403 );
		}
		$to = Paths::resolve( (string) $input['to'], false );
		if ( is_wp_error( $to ) ) {
			return $to;
		}
		if ( Paths::is_protected_file( $to ) ) {
			return self::err( 'godmode_fs_protected', Paths::display( $to ) . ' is a protected path and cannot be written through AI Godmode.', 403 );
		}
		if ( is_link( $to ) ) {
			return self::err( 'godmode_fs_symlink', Paths::display( $to ) . ' is a symlink; writing through it is refused.', 403 );
		}
		if ( file_exists( $to ) ) {
			if ( empty( $input['overwrite'] ) ) {
				return self::err( 'godmode_fs_exists', Paths::display( $to ) . ' already exists. Pass overwrite true to replace it.', 409 );
			}
			if ( Paths::is_protected_dir( $to ) ) {
				return self::err( 'godmode_fs_protected', Paths::display( $to ) . ' is a protected directory.', 403 );
			}
			$r = Paths::rm_rf( $to );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		if ( ! Paths::fs()->move( $from, $to ) ) {
			return self::err( 'godmode_fs_move_failed', 'Could not move ' . Paths::display( $from ) . '.', 500 );
		}
		return array( 'from' => Paths::display( $from ), 'to' => Paths::display( $to ), 'moved' => true );
	}
}

final class Fs_Delete extends Base {
	public static function name(): string {
		return 'godmode/fs-delete';
	}
	public static function definition(): array {
		return self::make(
			'filesystem',
			'Delete',
			'Delete a file, or a directory when recursive is true. Refuses protected directories (root, wp-admin, wp-includes, wp-content, plugins, AI Godmode itself).',
			self::obj( array( 'path' => self::str( 1024, 1 ), 'recursive' => self::bool( false ) ) + self::ack(), array( 'path' ) ),
			self::obj( array( 'path' => self::str(), 'deleted' => array( 'type' => 'integer' ) ), array( 'path', 'deleted' ) ),
			true
		);
	}
	public function execute( $input ) {
		$abs = Paths::resolve( (string) $input['path'] );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( Paths::is_protected_dir( $abs ) || Paths::is_protected_file( $abs ) ) {
			return self::err( 'godmode_fs_protected', Paths::display( $abs ) . ' is a protected path.', 403 );
		}
		if ( is_dir( $abs ) && ! is_link( $abs ) && empty( $input['recursive'] ) ) {
			return self::err( 'godmode_fs_is_dir', Paths::display( $abs ) . ' is a directory. Pass recursive true to delete it and everything inside.' );
		}
		$r = Paths::rm_rf( $abs );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return array( 'path' => Paths::display( $abs ), 'deleted' => (int) $r );
	}
}

final class Fs_Chmod extends Base {
	public static function name(): string {
		return 'godmode/fs-chmod';
	}
	public static function definition(): array {
		return self::make(
			'filesystem',
			'Change Permissions',
			'Set permissions (octal string such as 0644 or 755) on a file or directory, optionally recursively.',
			self::obj( array( 'path' => self::str( 1024, 1 ), 'mode' => self::str( 4, 3 ), 'recursive' => self::bool( false ) ) + self::ack(), array( 'path', 'mode' ) ),
			self::obj( array( 'path' => self::str(), 'mode' => self::str(), 'changed' => array( 'type' => 'integer' ) ), array( 'path', 'mode', 'changed' ) ),
			true
		);
	}
	public function execute( $input ) {
		$abs = Paths::resolve( (string) $input['path'] );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( Paths::is_protected_file( $abs ) ) {
			return self::err( 'godmode_fs_protected', Paths::display( $abs ) . ' is a protected path.', 403 );
		}
		$mode_s = (string) $input['mode'];
		if ( ! preg_match( '/^[0-7]{3,4}$/', $mode_s ) ) {
			return self::err( 'godmode_fs_bad_mode', 'Mode must be an octal string like 0644 or 755.' );
		}
		$mode    = octdec( $mode_s );
		$changed = 0;
		$apply   = function ( string $p ) use ( &$apply, &$changed, $mode, $input ) {
			if ( Paths::fs()->chmod( $p, $mode ) ) {
				$changed++;
			}
			if ( is_dir( $p ) && ! is_link( $p ) && ! empty( $input['recursive'] ) ) {
				foreach ( scandir( $p ) as $n ) {
					if ( '.' !== $n && '..' !== $n ) {
						$apply( $p . '/' . $n );
					}
				}
			}
		};
		$apply( $abs );
		return array( 'path' => Paths::display( $abs ), 'mode' => $mode_s, 'changed' => $changed );
	}
}

final class Fs_Zip extends Base {
	public static function name(): string {
		return 'godmode/fs-zip';
	}
	public static function definition(): array {
		return self::make(
			'filesystem',
			'Zip',
			'Create a zip archive of a file or directory under the WordPress root. The archive path must not exist. Files whose whole content is a secret (wp-config.php, .env, .htpasswd, private-key .pem, *.key) are left out and listed in skipped, because an archive cannot be scrubbed.',
			self::obj( array( 'path' => self::str( 1024, 1 ), 'archive' => self::str( 1024, 1 ) ) + self::ack(), array( 'path', 'archive' ) ),
			self::obj( array( 'archive' => self::str(), 'bytes' => array( 'type' => 'integer' ), 'entries' => array( 'type' => 'integer' ), 'skipped' => self::list_of( self::str() ) ), array( 'archive', 'bytes', 'entries' ) ),
			true
		);
	}
	public function execute( $input ) {
		if ( ! class_exists( '\\ZipArchive' ) ) {
			return self::err( 'godmode_fs_no_zip', 'The PHP zip extension is not available on this server.', 501 );
		}
		$src = Paths::resolve( (string) $input['path'] );
		if ( is_wp_error( $src ) ) {
			return $src;
		}
		$dst = Paths::resolve( (string) $input['archive'], false );
		if ( is_wp_error( $dst ) ) {
			return $dst;
		}
		if ( Paths::is_protected_file( $dst ) ) {
			return self::err( 'godmode_fs_protected', Paths::display( $dst ) . ' is a protected path and cannot be written through AI Godmode.', 403 );
		}
		if ( file_exists( $dst ) || is_link( $dst ) ) {
			return self::err( 'godmode_fs_exists', Paths::display( $dst ) . ' already exists.', 409 );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $dst, \ZipArchive::CREATE ) ) {
			return self::err( 'godmode_fs_zip_failed', 'Could not create the archive.', 500 );
		}
		$count   = 0;
		$skipped = array();
		$base    = is_dir( $src ) ? rtrim( $src, '/' ) : dirname( $src );
		$add     = function ( string $p ) use ( &$add, &$count, &$skipped, $zip, $base, $dst ) {
			if ( $p === $dst ) {
				return;
			}
			$local = ltrim( substr( $p, strlen( $base ) ), '/' );
			if ( is_dir( $p ) && ! is_link( $p ) ) {
				if ( '' !== $local ) {
					$zip->addEmptyDir( $local );
				}
				foreach ( scandir( $p ) as $n ) {
					if ( '.' !== $n && '..' !== $n ) {
						$add( $p . '/' . $n );
					}
				}
			} elseif ( is_file( $p ) ) {
				// Raw bytes cannot be scrubbed, so whole-secret files stay out.
				if ( Scrub::sensitive_file( $p ) ) {
					$skipped[] = Paths::display( $p );
					return;
				}
				$zip->addFile( $p, $local );
				$count++;
			}
		};
		$add( $src );
		$zip->close();
		return array( 'archive' => Paths::display( $dst ), 'bytes' => (int) filesize( $dst ), 'entries' => $count, 'skipped' => $skipped );
	}
}

final class Fs_Unzip extends Base {
	public static function name(): string {
		return 'godmode/fs-unzip';
	}
	public static function definition(): array {
		return self::make(
			'filesystem',
			'Unzip',
			'Extract a zip archive under the WordPress root into a directory (created if missing). Entries that would escape the target, symlink entries, and entries landing on protected files or existing symlinks are skipped and reported.',
			self::obj( array( 'archive' => self::str( 1024, 1 ), 'to' => self::str( 1024, 1 ), 'overwrite' => self::bool( false ) ) + self::ack(), array( 'archive', 'to' ) ),
			self::obj( array( 'to' => self::str(), 'extracted' => array( 'type' => 'integer' ), 'skipped' => self::list_of( self::str() ) ), array( 'to', 'extracted', 'skipped' ) ),
			true
		);
	}
	public function execute( $input ) {
		if ( ! class_exists( '\\ZipArchive' ) ) {
			return self::err( 'godmode_fs_no_zip', 'The PHP zip extension is not available on this server.', 501 );
		}
		$src = Paths::resolve( (string) $input['archive'] );
		if ( is_wp_error( $src ) ) {
			return $src;
		}
		$to = Paths::resolve_new( (string) $input['to'] );
		if ( is_wp_error( $to ) ) {
			return $to;
		}
		if ( Paths::is_protected_file( $to ) ) {
			return self::err( 'godmode_fs_protected', Paths::display( $to ) . ' is a protected path and cannot be written through AI Godmode.', 403 );
		}
		if ( is_link( $to ) ) {
			return self::err( 'godmode_fs_symlink', Paths::display( $to ) . ' is a symlink; extracting through it is refused.', 403 );
		}
		if ( ! is_dir( $to ) && ! wp_mkdir_p( $to ) ) {
			return self::err( 'godmode_fs_mkdir_failed', 'Could not create the target directory.', 500 );
		}
		$to_real = realpath( $to );
		$zip     = new \ZipArchive();
		if ( true !== $zip->open( $src ) ) {
			return self::err( 'godmode_fs_zip_failed', 'Could not open the archive.', 500 );
		}
		$extracted = 0;
		$skipped   = array();
		$to_rel    = Paths::display( $to_real );
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name  = $zip->getNameIndex( $i );
			$clean = str_replace( '\\', '/', $name );
			// Zip-slip: an entry name is attacker data. It must be a plain
			// relative name, must not be a symlink (which could be followed by
			// the next entry), and must resolve, through whatever already exists
			// on disk, to somewhere inside the target.
			if ( '' === $clean || false !== strpos( $clean, "\0" ) || 0 === strpos( $clean, '/' ) || preg_match( '#^[A-Za-z]:/#', $clean ) || in_array( '..', explode( '/', $clean ), true ) ) {
				$skipped[] = $name;
				continue;
			}
			$opsys = 0;
			$attr  = 0;
			if ( $zip->getExternalAttributesIndex( $i, $opsys, $attr ) && \ZipArchive::OPSYS_UNIX === $opsys && 0120000 === ( ( $attr >> 16 ) & 0170000 ) ) {
				$skipped[] = $name;
				continue;
			}
			$dest = Paths::resolve_new( ( '.' === $to_rel ? '' : $to_rel . '/' ) . $clean );
			if ( is_wp_error( $dest ) || 0 !== strpos( $dest . '/', $to_real . '/' ) || is_link( $dest ) || Paths::is_protected_file( $dest ) ) {
				$skipped[] = $name;
				continue;
			}
			if ( '/' === substr( $clean, -1 ) ) {
				wp_mkdir_p( $dest );
				continue;
			}
			if ( file_exists( $dest ) && empty( $input['overwrite'] ) ) {
				$skipped[] = $name;
				continue;
			}
			wp_mkdir_p( dirname( $dest ) );
			$stream = $zip->getStream( $name );
			if ( ! $stream ) {
				$skipped[] = $name;
				continue;
			}
			// file_put_contents() accepts a stream and copies it without loading the entry into memory.
			$written = file_put_contents( $dest, $stream );
			if ( is_resource( $stream ) ) {
				fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the zip entry stream, not a file this plugin opened.
			}
			if ( false === $written ) {
				$skipped[] = $name;
				continue;
			}
			$extracted++;
		}
		$zip->close();
		return array( 'to' => Paths::display( $to_real ), 'extracted' => $extracted, 'skipped' => $skipped );
	}
}
