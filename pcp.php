<?
	/**
	 * PCP Engine
	 *
	 * @example
	 * $pcp = new PCP(get_file_contents('.pcp-cache'));
	 * $pcp->add_source(array('src/layout.pcp', 'src/typography.pcp'));
	 * $pcp->parse();
	 * file_put_contents('min.css', $pcp->css());
	 * file_put_contents('min.js', $pcp->js());
	 * file_put_contents('.pcp-php-cache', $pcp->cache());
	 */
	class PCP
	{
		private $state = array(
			  'sources' => array()
			, 'variables' => array()
			// $state['selectors']['div.foo#bar'] = array(PCP_Property, PCP_Property, ...)
			, 'selectors' => array()
		);

		private $pseudo_classes = array(
			// CSS 1
			  'active'
			, 'hover'
			, 'link'
			, 'visited'

			// CSS 2
			, 'first-child'
			, 'focus'
			, 'lang'

			// CSS 3
			, 'nth-child'
			, 'nth-last-child'
			, 'nth-of-type'
			, 'nth-last-of-type'
			, 'last-child'
			, 'first-of-type'
			, 'last-of-type'
			, 'only-child'
			, 'only-of-type'
			, 'root'
			, 'empty'
			, 'target'
			, 'enabled'
			, 'disabled'
			, 'checked'
			, 'not'
		);

		/**
		 * @param string $cache Filename of engine cache
		 */
		function __construct($cache = null)
		{
			if($cache)
				$this->state = unserialize(@file_get_contents($cache));
		}

		/// @param string|array $source .pcp/.css file to add to the list of files to parse
		function add_source($source)
		{
			if(is_array($source))
			{
				foreach($source as $src)
					if(file_exists($src))
						array_push($this->state['sources'], $src);
			} else
			{
				if(file_exists($source))
					array_push($this->state['sources'], $source);
			}
		}
		
		/// Clear state, parse sources into state
		function parse()
		{
			// Clear the state
			$this->state['variables'] = array();
			$this->state['selectors'] = array();

			foreach($this->state['sources'] as $src)
			{
				if(false !== ($fd = fopen($src, 'r')))
				{
					$ln = 0; $cn = 0;		// Line number, Column number
					$buf = '';				// Chomped input
					$selector = array();	// Stack of working selectors
					$p;						// Current property. @see PCP_Property
					$fselectors = array();	// Per-file state. @see $state

					while(false !== ($c = fgetc($fd)))
					{
						switch($c)
						{
							case '{':	// selector { properties

								// Convert buffer into selector, then clear
								if(false === ($sel = $this->clean_token($buf))) fclose($fd);
								$buf = '';

								// Add selector to stack
								if(count($selector))
									array_push($selector, end($selector).'>'.$sel);
								else
									array_push($selector, $sel);

								break;

							case '}':	// properties }
								
								// Check for no open selectors
								if(count($selector) < 1)
								{
									trigger_error("$src:$ln:$cn: Unexpected '}'", E_USER_ERROR);
									fclose($fd);
								} else
								{
									// Check for open property
									if($p)
									{	
										// Check for potential value in the buffer
										if(strlen(trim($buf)))
										{
											trigger_error(
												  "$src:$ln:$sc: Property not closed properly. "
												 ."Assumed '{$p->name}: ".trim($buf).";'"
												, E_USER_WARNING
											);

										} else
										{
											trigger_error(
												  "$src:$ln:$cn: Selector closed with open property"
												, E_USER_ERROR
											);
											fclose($fd);
										}
									}
									array_pop($selector);
								}

								break;

							case ':':	// property name : property value

								// Is there already an open property?
								if($p)
									$buf .= ':';
								else
								{
									// Look ahead for pseudo-class names
									// We need to do this to differentiate between nested selectors
									// and properties.
									// TODO Maybe redo this to just see which comes first of (;|{)?
									$la = fread($fd, 24);
									fseek($fd, -(strlen($la)), SEEK_CUR);
									$la = preg_replace('/\W.*/', '', $la);

									// If next word is a valid pseudo-class, pass ':' through to $buf
									if(in_array($la, $this->pseudo_classes))
									{
										$buf .= ':';
										break;
									}

									$p = new PCP_Property(
										  end($selector)
										, $this->clean_token($buf)
									);

									$p->src = $src;
									$p->ln = $ln;
									$p->cn = $cn;

									$buf = '';
									$fselectors[end($selector)][$p->name] = &$p;
								}

								break;

							case ';':	// property value ;

								if($p)
								{
									$p->set(trim($buf));
									$buf = '';
									unset($p);
								} else
								{
									trigger_error(
										  "$src:$ln:$cn: Found ';' with no open property"
										, E_USER_ERROR
									);
									fclose($fd);
								}

								break;

							case "\n":	// Newline
								$buf .= ' ';
								$ln++;
								$cn = 0;
								break;

							default:	// chomp
								$buf .= $c;
								$cn++;
								break;
						}

					} // while(fgetc())

					// Check file is still open (didn't bomb)
					if($fd)
					{
						// Merge file state into global state
						foreach($fselectors as $selector => $properties)
						{
							foreach($properties as $p)
							{
								// Have we declared this property for this selector aready?
								if(isset($this->state['selectors'][$selector][$p->name]))
								{
									$op = $this->state['selectors'][$selector][$p->name];
									trigger_error(
										  "{$p->src}:{$p->ln}:{$p->cn}: Redefining property "
										 ."'{$p->name}' in '{$selector}'.\n"
										 ."Previously declared at {$op->src}:{$op->ln}:{$op->cn}"
										, E_USER_WARNING
									);
								}

								$this->state['selectors'][$selector][$p->name] = $p;
							}
						}
							

						fclose($fd);
					}
				} // fopen()
			} // foreach($state['sources'])
		}

		/// Return a string representing the state of the engine
		function cache()
		{
			return serialize($state);
		}

		/// Write static CSS
		function css()
		{
			ob_start();

			foreach($this->state['selectors'] as $selector => $properties)
			{
				echo "$selector{";
				
				foreach($properties as $p)
					echo "{$p->name}:{$p->value()};";

				echo '}';
			}

			return ob_get_clean();
		}

		/// Write javascript engine
		function js()
		{
		}
		/// Validate selectors/property names and prepare for use as a hash
		function clean_token($sel)
		{
			return preg_replace(
				array(
					  '/\s+>\s+/'			// Strip whitespace from around '>'
					, '/\s+\+\s+/'			// Strip whitespace from around '+'
					, '/\s\s+/'				// Strip extraneous whitespace
					, '/^\s+/'				// Strip whitespace from beginning
					, '/\s+$/'				// Strip whitespace from end
					, '/\/\*.*?\*\//'		// /* Comment */
					, '/\/\/.*?$/'			// // Comment
				), array(
					  '>'
					, '+'
					, ' '
				), $sel
			);
		}
	}

	class PCP_Property
	{
		var $name;				/// string
		var $value;				/// string Unprocessed value
		var $rvalue;			/// string Real value
		var $src;				/// string Source file
		var $ln;				/// int Line number
		var $cn;				/// int Column number
		var $selector;			/// string Selector containing this property
		var $deps;				/// array Properties this depends on. @see PCP_Property
		var $changed;			/// bool Has set() been called since last value()

		function __construct($selector, $name)
		{
			// TODO Validate these inputs, add error messages
			$this->deps = array();
			$this->name = $name;
			$this->selector = $selector;
		}

		/**
		 * Compute new value
		 */
		function value()
		{
			// Return precomputed value if no inputs have changed
			if(!$this->changed())
				return $this->rvalue;

			// TODO Parse property, replace dependency tokens with $dep->value()
			$this->rvalue = $this->value;

			// Reset changed indicator and return real value
			$this->changed = false;
			return $this->rvalue;
		}

		/**
		 * Set new value
		 */
		function set($new)
		{
			$this->value = $new;

			// Tell changed() about the new value
			$this->changed = true;

			// Invalidate dependencies
			$this->deps = null;
		}

		/**
		 * Has $this->value, or any dependency values changed?
		 * This function will be called frequently - it must be fast
		 * @returns bool
		 */
		function changed()
		{
			// Check for changed $this->value
			if($this->changed == true)
				return true;

			// Check for changed dependencies
			foreach($this->deps() as $dep)
				if($dep->changed())
					return true;

			// Nothing changed
			return false;
		}

		/**
		 * @returns array Array of @see PCP_Property dependencies
		 */
		private function deps()
		{
			// Return cached value
			if(null !== $this->deps)
				return $this->deps;

			$this->deps = array();

			// Find variables and PCP expressions in value
			preg_match_all('/\$(\(.*?\)|[\w-]*)/', $this->value, $matches);

			// Loop through found $ tokens
			foreach($matches[0] as $dep)
			{
				// Parse tokens into selector->property pairs or just property names
				preg_match('/\$\((.*?)\s*->\s*(.*?)\s*\)|(\$[\w-]*)/', $dep, $splitdep);

				if($splitdep[3])
				{
					// Property name 

					// Broaden scope until we find the named property
					$scope = $this->selector;
					while(strlen($scope))
					{
						if(isset($pcp->state['selectors'][$scope][$splitdep[3]]))
						{
							$this->deps[] = $pcp->state['selectors'][$scope][$splitdep[3]];
							$scope = '';
						} else
							$scope = preg_replace('/[ >+]?.*$/', '', $scope);
					}
				} else
				{
					// Selector->Property
				}
			}

			return $this->deps;
		}
	}
	/**
	 * Get or set a single property or variable in a selector
	 */
	function pcp($selector, $property, $value = null)
	{
	}
?>
