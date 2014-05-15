<?php
/**
 * Loco admin
 */
abstract class LocoAdmin { 

    /**
     * Print error
     */
    public static function error( $message, $label = '' ){
        if( defined('DOING_AJAX') && DOING_AJAX ){
            throw new Exception( $message );
        }
        // Translators: Bold text label in admin error messages
        $label or $label = _x('Error','Message label');
        echo '<div class="loco-message error loco-error"><p><strong>',$label,':</strong> ',Loco::html($message),'</p></div>';
    }
    
    
    /**
     * Print warning notice
     */
    public static function warning( $message, $label = '' ){
        $label or $label = _x('Warning','Message label');
        echo '<div class="loco-message updated loco-warning"><p><strong>',$label,':</strong> ',Loco::html($message),'</p></div>';
    }
    
    
    /**
     * Print success
     */
    public static function success( $message, $label = '' ){
        $label or $label = _x('OK','Message label');
        echo '<div class="loco-message updated loco-success"><p><strong>',$label,':</strong> ',Loco::html($message),'</p></div>';
    }
    
    
    /**
     * Exit forbidden
     */
    private static function forbid(){
        wp_die( Loco::__('Permission denied'), 'Forbidden', array('response' => 403 ) );
        trigger_error('wp_die failure', E_USER_ERROR );
        exit();
    }     
    
    
    
    /**
     * Admin settings page render call
     */
    public static function render_page_options(){
        current_user_can(Loco::CAPABILITY) or self::forbid();
        // update applicaion settings if posted
        if( isset($_POST['loco']) && is_array( $update = $_POST['loco'] ) ){
            $update += array( 'gen_hash' => '0' );
            $args = Loco::config( $update );
            $args['success'] = Loco::__('Settings saved');
        }
        else {
            $args = Loco::config();
        }
        // establish a default msgfmt if required and possible
        if( $args['use_msgfmt'] && ! $args['which_msgfmt'] ){
            function_exists('loco_find_executable') or loco_require('build/shell-compiled');
            $args['which_msgfmt'] = loco_find_executable('msgfmt');// and Loco::config( $args );
        }
        Loco::enqueue_scripts('build/admin-common');
        Loco::render('admin-opts', $args );
    }     
    
    
      
    /**
     * Admin tools page render call
     */
    public static function render_page_tools(){
        current_user_can(Loco::CAPABILITY) or self::forbid();
        do {
            try {
                
                // libs required for all manage translation pages
                loco_require('loco-locales','loco-packages');
                
                // most actions except root listing define a single package by name and type
                $package = null;
                if( isset($_GET['name']) && isset($_GET['type']) ){
                    $package = LocoPackage::get( $_GET['name'], $_GET['type'] );
                }

                // Extract messages if 'xgettext' is in query string
                //
                if( isset($_GET['xgettext']) ){
                    $domain = $_GET['xgettext'];
                    if( $pot_path = $package->get_pot($domain) ){
                        throw new Exception('POT already exists at '.$pot_path );
                    }
                    // Establish best/intended location for new POT file
                    $dir = $package->lang_dir( $domain );
                    $pot_path = $dir.'/'.$domain.'.pot';
                    $export = self::xgettext( $package, $dir );
                    self::render_poeditor( $package, $pot_path, $export );
                    break;
                }


                // Initialize a new PO file if 'msginit' is in query string
                //
                if( isset($_GET['msginit']) ){
                    $domain = $_GET['msginit'];
                    $force_global = isset($_GET['gforce']) ? (bool) $_GET['gforce'] : null;
                    // handle PO file creation if locale is set
                    if( isset($_GET['custom-locale']) ){
                        try {
                            $locale = $_GET['custom-locale'] or $locale = $_GET['common-locale'];
                            $po_path = self::msginit( $package, $domain, $locale, $export, $head, $force_global );
                            if( $po_path ){
                                self::render_poeditor( $package, $po_path, $export, $head );
                                break;
                            }
                        }
                        catch( Exception $Ex ){
                            // fall through to msginit screen with error
                            self::error( $Ex->getMessage() );
                        }
                    }    
                    // else do a dry run to pre-empt failures and allow manual alteration of target path
                    $path = self::msginit( $package, $domain, 'en', $export, $head, $force_global );
                    // get alternative location options
                    $pdir = $package->lang_dir( $domain, true );
                    $gdir = $package->global_lang_dir();
                    // render msginit start screen
                    $title = Loco::__('New PO file');
                    $locales = LocoLocale::get_names();
                    Loco::enqueue_scripts( 'build/admin-common', 'build/admin-poinit');
                    Loco::render('admin-poinit', compact('package','domain','title','locales','path','pdir','gdir') );
                    break;
                }


                // Render existing file in editor if 'poedit' contains a valid file path relative to content directory
                //
                if( isset($_GET['poedit']) && $po_path = self::resolve_path( $_GET['poedit'] ) ){
                    $export = self::parse_po_with_headers( $po_path, $head );
                    self::render_poeditor( $package, $po_path, $export, $head );
                    break;
                }
                
                
                // Show filesystem check if 'fscheck' in query
                //
                if( isset($_GET['fscheck']) ){
                    $meta = $package->meta();
                    Loco::enqueue_scripts('build/admin-common');
                    Loco::render('admin-fscheck', $meta + compact('package') );
                    break;
                }
                
                
            }
            catch( Exception $Ex ){
                self::error( $Ex->getMessage() );
            }
            
            // default screen renders root page with available themes and plugins to translate
    
            // @var WP_Theme $theme
            $themes = array();
            foreach( wp_get_themes( array( 'allowed' => true ) ) as $name => $theme ){
                $package = LocoPackage::get( $name, 'theme' ) and
                $name = $package->get_name();
                $themes[ $name ] = $package;
            }
            // @var array $plugin
            $plugins = array();
            foreach( get_plugins() as $plugin_file => $plugin ){
                $package = LocoPackage::get( $plugin_file, 'plugin' ) and
                $plugins[] = $package;
            }
            // @var array $core
            $core = array();
            $conf = Loco::config();
            if( ! empty($conf['enable_core']) ){
                foreach( LocoPackage::get_core_packages() as $package ){
                    // if package has no PO or POT we skip it because core packages have no source
                    if( $package->get_po() || $package->get_pot() ){
                        $core[] = $package;
                    }
                }
            }
            // order most active packges first in each set
            $args = array (
                'themes'  => LocoPackage::sort_modified( $themes ),
                'plugins' => LocoPackage::sort_modified( $plugins ),
                'core'    => LocoPackage::sort_modified( $core ),
            );
            // upgrade notice
            if( $updates = get_site_transient('update_plugins') ){
                $key = Loco::NS.'/loco.php';
                if( isset($updates->checked[$key]) && isset($updates->response[$key]) ){
                    $old = $updates->checked[$key];
                    $new = $updates->response[$key]->new_version;
                    if( 1 === version_compare( $new, $old ) ){
                        // current version is lower than latest
                        $args['update'] = $new;
                    }
                }
            }
            Loco::render('admin-root', $args );
        }
        while( false );
    } 
    
    
    
    /**
     * utility gets newest file modification from an array of files
     */
    private static function newest_mtime_recursive( array $files ){
        $mtime = 0;    
        foreach( func_get_args() as $files ){
            foreach( $files as $path ){
                $mtime = max( $mtime, filemtime($path) );
            }
        }
        return $mtime;
    }    
    
    
    
    /**
     * Initialize a new PO file from a locale code
     * @return string path where PO file will be saved to
     */
    private static function msginit( LocoPackage $package, $domain = '', $code, &$export, &$head, $force_global = null ){
        $head = null;
        $export = array();
        $locale = $code ? loco_locale_resolve($code) : null;
        if( ! $locale ){
            throw new Exception( Loco::__('You must specify a valid locale for a new PO file') );
        }
        
        // default PO file location
        $po_path = $package->create_po_path( $locale, $domain, $force_global );
        $po_dir  = dirname( $po_path );
        $po_name = basename( $po_path );

        // extract strings from POT if possible
        if( $pot_path = $package->get_pot($domain) ){
            $pot = self::parse_po_with_headers( $pot_path, $head );
            if( $pot && ! ( 1 === count($pot) && '' === $pot[0]['source'] ) ){
                $export = $pot;
                $pot_dir = dirname( $pot_path );
                // override default PO location if POT location is writable and getting best location
                if( is_writable($pot_dir) && is_null($force_global) ){
                    $po_dir = $pot_dir;
                }
            }
        }

        // else extract strings from source code when no POT
        if( ! $export ){
            $export = self::xgettext( $package, $po_dir );
            if( ! $export ){
                throw new Exception( Loco::__('No translatable strings found').'. '.Loco::__('Cannot create a PO file.') );
            }
        }
        
        // check for PO conflict as this is msginit, not a sync.
        $po_path = $po_dir.'/'.$po_name;
        if( file_exists($po_path) ){
            throw new Exception( sprintf(Loco::__('PO file already exists with locale %s'), $po_code ) );
        }

        // return path, export and head set as references
        $head or $head = new LocoArray;
        return $po_path;
    }     
    
    
    
    
    
    /**
     * Render poedit screen
     * @param string optional package root directory
     * @param string PO or PO file path
     * @param array data to load into editor
     */
    private static function render_poeditor( LocoPackage $package, $path, array $data, LocoArray $head = null ){
        $pot = $po = $locale = null;
        $warnings = array();
        // remove header and check if empty
        $minlength = 1;
        if( isset($data[0]['source']) && $data[0]['source'] === '' ){
            $data[0] = array();
            $minlength = 2;
        }
        // template file is developer-editable and has no locale
        $ispot = self::is_pot($path);

        // path may not exist if we're creating a new one
        if( file_exists($path) ){
            $modified = self::format_datetime( filemtime($path) );
        }
        else {
            $modified = 0;
        }
        
        // support incorrect usage of template PO files
        if( ! $ispot && $head && $modified && ! $head->Language && self::none_translated($data) ){
            $path .= 't';
            $warnings[] = sprintf( Loco::__('PO file used as template. This will be renamed to %s on first save'), basename($path) );
            $ispot = true;
            $modified = 0;
        }
        
        if( $ispot ){
            $pot = $data;
            $type = 'POT';
        }
        // else PO is locked and has a locale
        else {
            $po = $data;
            $type = 'PO';
            $locale = self::resolve_file_locale($path);
            $domain = self::resolve_file_domain($path);
            $haspot = $package->get_pot( $domain );
        }
        
        
        // warn if new file can't be written
        $writable = self::is_writable( $path );
        if( ! $writable && ! $modified ){
            //$message = $modified ? Loco::__('File cannot be saved to disk automatically'): Loco::__('File cannot be created automatically');
            //$warnings[] = $message.'. '.sprintf(Loco::__('Fix the file permissions on %s'),$path);
            $warnings[] = Loco::__('File cannot be created automatically. Fix the file permissions or use Download instead of Save');
        }
        
        // Warnings if file is empty
        if( count($data) < $minlength ){
            $lines = array();
            if( $ispot ){
                if( $modified ){
                    // existing POT, may need sync
                    $lines[] = sprintf( Loco::__('%s file is empty'), 'POT' );
                    $lines[] = Loco::__('Run Sync to update from source code');
                }
                else {
                    // new POT, would have tried to extract from source. Fine you can add by hand
                    $lines[] = Loco::__('No strings could be extracted from source code');
                }
            }
            else if( $modified ){
                $lines[] = sprintf( Loco::__('%s file is empty'), 'PO' );
                if( $haspot ){
                    // existing PO that might be updatable from POT
                    $lines[] = sprintf( Loco::__('Run Sync to update from %s'), basename($haspot) );
                }
                else {
                    // existing PO that might be updatable from sources
                    $lines[] = Loco::__('Run Sync to update from source code');
                }
            }
            else {
                // this shouldn't happen if we throw an error during msginit
                throw new Exception( Loco::__('No translatable strings found') );
            }
            $warnings[] = implode('. ', $lines );
        }

        // warning if file needs syncing
        else if( $modified ){
            if( $ispot ){
                $sources = $package->get_source_files();
                if( $sources && filemtime($path) < self::newest_mtime_recursive($sources) ){
                    $warnings[] = Loco::__('Source code has been modified, run Sync to update POT');
                }
            }
            else if( $haspot && filemtime($haspot) > filemtime($path) ){
                $warnings[] = Loco::__('POT has been modified since PO file was saved, run Sync to update');
            }
        }

        // extract some PO headers
        if( $head instanceof LocoArray ){
            $proj = $head->trimmed('Project-Id-Version');
            if( $proj && 'PACKAGE VERSION' !== $proj ){
                $name = $proj;
            }
        }
        else {
            $head = new LocoArray;
        }
        
        // set Last-Translator if PO file
        if( ! $ispot ){
            /* @var WP_User $user */
            $user = wp_get_current_user() and
            $head->add( 'Last-Translator', $user->get('display_name').' <'.$user->get('user_email').'>' );
        }
        
        // overwrite source location headers
        // create a relative path to target source directory from location of PO
        if( ! $head->has('X-Poedit-Basepath') ){
            $head->add('X-Poedit-Basepath', '.' );
            foreach( $package->get_source_dirs($path) as $i => $dir ){
                $dir or $dir = '.';
                $head->add('X-Poedit-SearchPath-'.$i, $dir );
            }
        }
        
        // compiled keywords for running source extraction in POEdit
        // note that these aren't just wordpress keywords, but they're the same as we're using in self::xgettext
        $ext = new LocoPHPExtractor;
        $head->add('X-Poedit-KeywordsList', implode( ';', $ext->get_xgettext_keywords() ) );
    
        // ensure nice name for project
        if( ! isset($name) ){
            $meta = $package->meta();
            $name = $meta['name'];
        }
        $head->add( 'Project-Id-Version', $name );
        $headers = $head->to_array();

        // no longer need the full local paths
        $path = self::trim_path( $path );
        
        // If parsing MO file, from now on treat as PO
        if( ! $ispot && self::is_mo($path) ){
            $path = str_replace( '.mo', '.po', $path );
        }

        Loco::enqueue_scripts('build/admin-poedit');
        Loco::render('admin-poedit', compact('package','path','po','pot','locale','headers','name','type','modified','writable','warnings') );
        return true;
    }
    
    
    
    /**
     * test if a file path is a POT (template) file
     */
    public static function is_pot( $path ){
        return 'pot' === strtolower( pathinfo($path,PATHINFO_EXTENSION) );
    }
    
    
    
    /**
     * test if a file path is a MO (compiled) file
     */
    public static function is_mo( $path ){
        return 'mo' === strtolower( pathinfo($path,PATHINFO_EXTENSION) );
    }
    
    
    
    /**
     * resolve file path that may be relative to wp-content
     */
    public static function resolve_path( $path, $isdir = false ){
        if( $path && '/' !== $path{0} ){
            $path = WP_CONTENT_DIR.'/'.$path;
        }
        $realpath = realpath( $path );
        if( ! $realpath || ! is_readable($realpath) || ( $isdir && ! is_dir($realpath) ) || ( ! $isdir && ! is_file($realpath) ) ){
            self::error( Loco::__('Bad file path').' '.var_export($path,1) );
            return '';
        }
        // returning original path in case something was symlinked outside the web root
        return $path;
    }
    
    
    
    /**
     * remove wp-content from path for more compact display in urls and such
     */
    public static function trim_path( $path ){
        return str_replace( WP_CONTENT_DIR.'/', '', $path );
    }    
    
    
    
    /**
     * Test whether a file can be written to, whether it exists or not
     */
    public static function is_writable( $path ){
        // if file exists it must be writable itself:
        if( file_exists($path) ){
            return is_writable($path);
        }
        // else file must be created, which may mean recursive directory permissions
        $dir = dirname( $path );
        return is_dir($dir) && is_writable($dir);
    }
    
    
    
    /**
     * Recursively find PO and POT files under WP_LANG_DIR (wp-content/languages)
     * Then remove them so after all packages are processed we can pick up orphans.
     */
    public static function pop_lang_dir( $domain = '', $filtered = array() ){
        static $found;
        if( ! isset($found) ){
            $found = array();
            if( is_dir(WP_LANG_DIR) ){
                $found = self::find_po( WP_LANG_DIR );
            }
        }
        if( ! $domain ){
            return $found;
        }
        foreach( $found as $ext => $paths ){
            isset($filtered[$ext]) or $filtered[$ext] = array();
            foreach( $paths as $i => $path ){
                if( 0 === strpos( basename($path), $domain.'-' ) ){
                    $filtered[$ext][] = $path;
                    unset( $found[$ext][$i] );
                }
            }
        }
        return $filtered;
    }
    
    
    
    /**
     * Recursively find all PO and POT files anywhere under a directory
     */
    public static function find_po( $dir ){
        return self::find( $dir, array('po','pot') );
    }
    
    
    
    /**
     * Recursively find all MO files anywhere under a directory
     */
    public static function find_mo( $dir ){
        $files = self::find( $dir, array('mo') );
        return $files['mo'];
    }


    
    /**
     * Recursively find all POT files anywhere under a directory
     */
    public static function find_pot( $dir ){
        $files = self::find( $dir, array('pot') );
        return $files['pot'];
    }
    
    
    
    /**
     * Recursively find all PHP source files anywhere under a directory
     */
    public static function find_php( $dir ){
        $files = self::find( $dir, array('php') );
        return $files['php'];
    }
    
    
    
    /**
     * Recursively find files of any given extensions
     */
    private static function find( $dir, array $exts ){
        $options = 0;
        $found = array_fill_keys( $exts, array() );
        $exts = implode(',',$exts);
        if( isset($exts[1]) ){
            $options |= GLOB_BRACE;
            $exts = '{'.$exts.'}';
        }
        return self::find_recursive( $dir, '/*.'.$exts, $options, $found );
    }
    
    
    
    /**
     * @internal
     * @param string path to start with no trailing slash
     * @param string path pattern to match, e.g. "/*.po"
     * @param int optional GLOB_* options
     * @param array existing collation to add to in recursion
     * @return array collection of paths grouped by extension, { po: [..], pot: [..] }
     */
    private static function find_recursive( $dir, $pattern, $options, array $found ){
        // collect files in this directory level
        $found = self::find_grouped( $dir.$pattern, GLOB_NOSORT|$options, $found ); 
        // recurse to subdirectories
        $sub = glob( $dir.'/*', GLOB_ONLYDIR|GLOB_NOSORT );
        if( is_array($sub) ){
            foreach( $sub as $dir ){
                $found = self::find_recursive( $dir, $pattern, $options, $found );
            }
        }
        return $found;
    }
    
    
    /**
     * @internal
     */
    public static function find_grouped( $pattern, $options = 0, $found = array() ){
        $files = glob( $pattern, $options );
        if( is_array($files) ){
            foreach( $files as $path ){
                $ext = strtolower( pathinfo($path,PATHINFO_EXTENSION ) );
                $found[$ext][] = $path;
            }
        }
        return $found;
    }    
    
    
    
    /**
     * Perform xgettext style extraction from PHP source files
     * @todo JavaScript files too
     * @todo filter on TextDomain?
     * @return array Loco's internal array format
     */
    public static function xgettext( LocoPackage $package, $relative_to = '' ){
        class_exists('LocoPHPExtractor') or loco_require('build/gettext-compiled');
        $extractor = new LocoPHPExtractor;
        $export = array();
        // extract from PHP sources, as long as source locations exist
        if( $srcdirs = $package->get_source_dirs() ){
            foreach( $srcdirs as $dir ){
                $fileref = loco_relative_path( $relative_to, $dir );
                foreach( self::find_php($dir) as $path ){
                    $source = file_get_contents($path) and
                    $tokens = token_get_all($source) and
                    $export = $extractor->extract( $tokens, str_replace( $dir, $fileref, $path ) );
                }
            }
        }
        // else use an existing PO file (should be used for core only)
        else if( $po = $package->get_po() ){
            foreach( $po as $code => $path ){
                $export = self::parse_po( $path );
                break;
            }
        }        
        return $export;
    }
    
    
    
    /**
     * Establish if translations are all empty
     */
    private static function none_translated( array $data ){
        foreach( $data as $message ){
            if( ! empty($message['target']) ){
                return false;
            }
        }
        return true;
    }
    
    
    
    /**
     * Parse MO, PO or POT file
     */
    public static function parse_po( $path ){
        function_exists('loco_parse_po') or loco_require('build/gettext-compiled');
        $source = trim( file_get_contents($path) );
        if( ! $source ){
            return array();
        }
        $parser = strpos($path,'.mo') ? 'loco_parse_mo' : 'loco_parse_po';
        return call_user_func( $parser, $source );
    }
    
    
    
    /**
     * Parse MO, PO or POT file, placing header object into argument
     */
    public static function parse_po_with_headers( $path, &$headers ){
        $export = self::parse_po( $path );
        if( ! isset($export[0]) ){
            $ext = strtoupper( pathinfo($path,PATHINFO_EXTENSION) );
            throw new Exception( sprintf( Loco::__('Empty or invalid %s file'), $ext ) );
        }
        if( $export[0]['source'] !== '' ){
            $ext = strtoupper( pathinfo($path,PATHINFO_EXTENSION) );
            throw new Exception( sprintf( Loco::__('%s file has no header'), $ext ) );
        }
        $headers = loco_parse_po_headers( $export[0]['target'] );
        $export[0] = array(); // <- avoid index errors as json
        return $export;
    }
    
    
    
    /**
     * Resolve a list of PO file paths to locale instances
     */
    private static function resolve_file_locales( array $files ){
        $locales = array();
        foreach( $files as $key => $path ){
            $locale = self::resolve_file_locale( $path );            
            $locales[$key] = $locale;
        }
        return $locales;
    }
    
    
    
    /**
     * Resolve a PO file path or file name to a locale.
     * Note that this does not read the file and the PO header, but perhaps it should. (performance!)
     * @return LocoLocale
     */
    public static function resolve_file_locale( $path ){
        $stub = str_replace( array('.po','.mo'), array('',''), basename($path) );
        $locale = loco_locale_resolve($stub);
        return $locale;
    }
    
    
    /**
     * Resolve a PO file path or file name to TextDomain.
     * Note that this does not parse the file to read any data, it just extracts from filename
     * @param string e.g. "path/to/foo-fr_FR.po" or "foo.pot"
     * @return string e.g. "foo"
     */
    public static function resolve_file_domain( $path ){
        extract( pathinfo($path) );
        if( ! isset($filename) ){
            $filename = str_replace( '.'.$extension, '', $basename ); // PHP < 5.2.0
        }
        if( 'pot' === $extension ){
            return $filename;
        }
        if( $domain = preg_replace('/[a-z]{2}(_[A-Z]{2})?$/', '', $filename ) ){
            return trim( $domain, '-' );
        }
        // empty domain means file name is probably just a locale
        return '';
    }
    
    
    /**
     * Resolve a PO file to a theme
     * @return WP_Theme
     */
    public static function resolve_file_theme( $path ){
        if( false !== strpos($path,'/themes/') ){
            $domain = self::resolve_file_domain($path);
            return wp_get_theme( $domain );
        }
    }
    
     
    /**
     * Generate an admin page URI with custom args
     */
    public static function uri( array $args = array(), $suffix = '' ){
        $base_uri = admin_url('admin.php');
        if( ! isset($args['page']) ){
            $args['page'] = Loco::NS;
            if( $suffix ){
                $args['page'].= '-'.$suffix;
            }
        }
        return $base_uri.'?'.http_build_query( $args );
    }
    
    
    
    /**
     * Test if we're on our own admin page
     * @param string optionally specify exact slug including Loco::NS
     * @return string current slug
     */
    public static function is_self( $page = null ){
        static $active;
        if( ! isset($active) ){
            $screen = get_current_screen();
            $splode = explode( Loco::NS, $screen->base, 2 );
            $active = isset($splode[1]) ? Loco::NS.$splode[1] : false;
        }
        if( false !== $active && ( is_null($page) || $page === $active ) ){
            return $active;
        }
        return '';
    }
    
    
    
    /**
     * Generate a link to edit a po/pot file
     */
    public static function edit_link( LocoPackage $package, $path, $label = '', $icon = '' ){
        $url = self::uri( $package->get_query() + array (
            'poedit' => self::trim_path( $path ),
        ) );
        if( ! $label ){
            $label = basename( $path );
        }
        $inner = Loco::html($label);
        if( $icon ){
            $inner = '<span class="'.$icon.'"></span>'.$inner;
        }
        return '<a href="'.Loco::html($url).'">'.$inner.'</a>';
    }
    
    
    
    /**
     * Generate a link to generate a new POT file
     */
    public static function xgettext_link( LocoPackage $package, $domain = '', $label = '' ){
        $url = self::uri( $package->get_query() + array(
            'xgettext' => $domain ? $domain : $package->get_domain(),
        ) );
        if( ! $label ){
            $label = Loco::_x('New template','Add button') ;
        }
        return '<a href="'.Loco::html($url).'">'.Loco::html($label).'</a>';
        
    }
    
    
    
    /**
     * Generate a link to create a new PO file for a not-yet-specified locale
     */
    public static function msginit_link( LocoPackage $package, $domain = '', $label = '' ){
        if( ! $domain ){
            $domain = $package->get_domain();
        }
        $url = self::uri(  $package->get_query() + array (
            'msginit' => $domain ? $domain : $package->get_domain(),
        ) );
        if( ! $label ){
            $label = Loco::_x('New language','Add button');
        }
        return '<a href="'.Loco::html($url).'">'.Loco::html($label).'</a>';
    }
    
    
    /**
     * Generate a link to check file permissions on a packge
     */
    public static function fscheck_link( LocoPackage $package, $domain = '', $label ){
        if( ! $domain ){
            $domain = $package->get_domain();
        }
        $url = self::uri(  $package->get_query() + array (
            'fscheck' => $domain ? $domain : $package->get_domain(),
        ) );
        return '<a href="'.Loco::html($url).'">'.Loco::html($label).'</a>';
    }    
     
     
    /**
     * Date format util
     */ 
    public static function format_datetime( $u ){
        static $tf, $df;
        if( ! $tf ){
            $tf = get_option('time_format') or $tf = 'g:i A';
            $df = get_option('date_format') or $df= 'M jS Y'; 
        }
        return date_i18n( $df.' '.$tf, $u ); 
    } 
    
    
    
    /**
     * PO translate progress summary
     */   
    public static function format_progress_summary( array $stats ){
        extract( $stats );
        $text = sprintf( Loco::__('%s%% translated'), $p ).', '.sprintf( Loco::_n('1 string', '%s strings', $t ), number_format($t) );
        $extra = array();
        if( $f ){
            $extra[] = sprintf( Loco::__('%s fuzzy'), number_format($f) );
        }   
        if( $u ){
            $extra[] = sprintf( Loco::__('%s untranslated'), number_format($f) );
        }
        if( $extra ){
            $text .= ' ('.implode(', ',$extra).')';
        }
        return $text;
    }
    
    
    /**
     * get configured path to external msgfmt command, including --no-hash argument 
     * @return string 
     */
    public static function msgfmt_command(){
        $conf = Loco::config();
        if( ! $conf['use_msgfmt'] || ! $conf['which_msgfmt'] ){
            return '';
        }
        $cmd = escapeshellarg( trim( $conf['which_msgfmt'] ) );
        if( ! $conf['gen_hash'] ){
            $cmd .= ' --no-hash';
        }
        return $cmd;
    }
    
    
    /**
     * Execute native msgfmt command
     * @param string po source
     * @return string binary mo source
     */
    public static function msgfmt_native( $po ){
        try {
            $conf = Loco::config();
            loco_require('build/gettext-compiled');
            $gen_hash = (bool) $conf['gen_hash'];
            $mo = loco_msgfmt( $po, $gen_hash );
        }
        catch( Exception $Ex ){
            error_log( $Ex->getMessage(), 0 );
        }
        if( ! $mo ){
            throw new Exception( sprintf( Loco::__('Failed to compile MO file with built-in compiler') ) );
        }
        return $mo;    
    }     

    
}




// admin filter and action callbacks
 

/**
 * Enqueue only admin styles we need
 */  
function _loco_hook__current_screen(){
    if( $slug = LocoAdmin::is_self() ){
        // redirect legacy links
        if( $i = strpos( $slug,'-legacy') ){
            $args = $_GET;
            $args['page'] = substr_replace( $slug, '', $i );
            $uri = LocoAdmin::uri( $args, $slug );
            wp_redirect( $uri );
        }
        // add common resources for all Loco admin pages
        Loco::enqueue_styles('loco-admin');
    }
}  



/**
 * Admin menu registration callback
 */
function _loco_hook__admin_menu() {
    $cap = LOCO::CAPABILITY;
    if( current_user_can($cap) ){
        // hook in legacy wordpress styles as menu will display
        $wp_38 = version_compare( $GLOBALS['wp_version'], '3.8', '>=' ) or
        Loco::enqueue_styles('loco-legacy');
        
        $page_title = Loco::__('Loco, Translation Management');
        $tool_title = Loco::__('Manage translations');
        $opts_title = Loco::__('Translation options');
        // Loco main menu item
        $slug = Loco::NS;
        $title = $page_title.' - '.$tool_title;
        $page = array( 'LocoAdmin', 'render_page_tools' );
        // Dashicons were introduced in WP 3.8
        $icon = $wp_38 ? 'dashicons-translation' : 'none';
        add_menu_page( $title, Loco::__('Loco Translate'), $cap, $slug, $page, $icon );
        // add main link under self with different name
        add_submenu_page( $slug, $title, $tool_title, $cap, $slug, $page );
        // also add under Tools menu (legacy)
        add_management_page( $title, $tool_title, $cap, $slug.'-legacy', $page );
        // Settings page
        $slug = $slug.'-settings';
        $title = $page_title.' - '.$opts_title;
        $page = array( 'LocoAdmin', 'render_page_options' );
        add_submenu_page( Loco::NS, $title, $opts_title, $cap, $slug, $page );
        // also add under Settings menu (legacy)
        add_options_page( $title, $opts_title, Loco::CAPABILITY, $slug.'-legacy', $page );
        // Hook in page stuff as soon as screen is avaiable
        add_action('current_screen', '_loco_hook__current_screen' );
    }        
}


/**
 * extra visibility of settings link
 */
function _loco_hook__plugin_row_meta( $links, $file = '' ){
    if( false !== strpos($file,'/loco.php') ){
        $links[] = '<a href="'.Loco::html( LocoAdmin::uri( array(), '' ) ).'"><strong>'.Loco::__('Manage translations').'</strong></a>';
        $links[] = '<a href="'.Loco::html( LocoAdmin::uri( array(), 'settings') ).'"><strong>'.Loco::__('Settings').'</strong></a>';
    } 
    return $links;
}


/**
 * execute ajax actions
 */
function _loco_hook__wp_ajax(){
    extract( Loco::postdata() );
    if( isset($action) ){
        require Loco::basedir().'/php/loco-ajax.php';
    }
}


/** 
 * execute file download actions
 */
function _loco_hook__wp_ajax_download(){
    extract( Loco::postdata() );
    if( isset($action) ){
        require Loco::basedir().'/php/loco-download.php';
        die( __('File download failed') );
    }
}



add_action('admin_menu', '_loco_hook__admin_menu' );
add_action('plugin_row_meta', '_loco_hook__plugin_row_meta', 10, 2 );

// ajax hooks all going through one central function
add_action('wp_ajax_loco-posave', '_loco_hook__wp_ajax' );
add_action('wp_ajax_loco-posync', '_loco_hook__wp_ajax' );
add_action('wp_ajax_loco-download', '_loco_hook__wp_ajax_download' );

// WP_LANG_DIR was introduced in Wordpress 2.1.0.
if( ! defined('WP_LANG_DIR') ){
    define('WP_LANG_DIR', WP_CONTENT_DIR.'/languages' );
} 
 
