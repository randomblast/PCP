<?
	global $pcp;
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
		var $state = array(
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
			if(file_exists($cache))
				$this->state = unserialize(@file_get_contents($cache));

			// Hook into global
			global $pcp;
			$pcp = &$this;
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
					$p = null;				// Current property. @see PCP_Property
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

		/**
		 * @param string $base Selector to extend
		 * @param string $sub Selector to put changes into
		 * @param array $delta name=>value pairs of properties to modify in the subclass
		 * @returns bool True on success, false on failure
		 */
		function extend($base, $sub, $delta)
		{
			// Get specified selector from $state, or bomb if it's not there
			if(false === ($properties = $this->state['selectors'][$base]))
				return false;

			// Make a copy of selector with new name
			foreach($this->state['selectors'][$base] as $old_p)
			{
				$p = clone $old_p;

				$p->selector = str_replace($base, $sub, $p->selector);
				$p->changed = false;

				$p->deps = null;
				$deps = $p->dependants;
				$p->dependants = array();


				// Clone dependant tree
				foreach($deps as $old_dep)
				{
					$dep = clone $old_dep;

					$dep->selector = str_replace($base, $sub, $dep->selector);

					$dep->deps = null;
					$dep->changed = false;

					$this->state['selectors'][$dep->selector][$dep->name] = $dep;
					$p->dependants["{$dep->selector}->{$dep->name}"] = $dep;

				}

				$this->state['selectors'][$sub][$p->name] = $p;
			}

			foreach($delta as $name => $value)
				$this->state['selectors'][$sub][$name]->set($value);
		}
		/// Return a string representing the state of the engine
		function cache()
		{
			return serialize($state);
		}

		/**
		 * Generate a string of static CSS
		 * @param bool $diff Should the output be a diff from the last call, or the entire state?
		 * @returns string Minified CSS
		 */
		function css($diff = true)
		{
			ob_start();

			foreach($this->state['selectors'] as $selector => $properties)
			{
				ob_start();

				// Don't output selectors with no changed properties
				$empty = true;

				echo "$selector{";
				
				foreach($properties as $p)
					if($p->name[0] != '$' && (!$diff || $p->changed()))
					{
						echo "{$p->name}:{$p->value(true)};";
						$empty = false;
					}

				echo '}';

				if(!$empty)
					echo ob_get_clean();
				else
					ob_clean();
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
		var $dependants;		/// array Properties that depend on this. @see PCP_Property
		var $changed;			/// bool Has set() been called since last value()

		function __construct($selector, $name)
		{
			// TODO Validate these inputs, add error messages
			$this->deps = array();
			$this->dependants = array();
			$this->name = $name;
			$this->selector = $selector;
		}

		/**
		 * Compute new value
		 * @param bool $is_output Decides whether the property will be marked as unchanged
		 */
		function value($is_output = false)
		{
			// Return precomputed value if no inputs have changed
			if(!$this->changed())
				return $this->rvalue;

			// Initialize rvalue
			$this->rvalue = $this->value;

			$deps = $this->deps();

			// Loop through deps, replace tokens with values
			foreach($deps as $dep => $p)
				if($p->value())
					$this->rvalue = str_replace($dep, $p->value(), $this->rvalue);

			// Reset changed indicator and return real value
			if($is_output)
				$this->changed = false;
			return $this->rvalue;
		}

	/**
	 * Register a dependant on this property.
	 * We need to be able to navigate down the dependancy tree in order to generate diffs.
	 * @param PCP_Property $p Property that wants to depend on this one
	 * @returns bool True on success, false on failure
	 */
	private function add_dependant(&$p)
	{
		if($p && !isset($this->dependants["{$p->selector}->{$p->name}"]))
			$this->dependants["{$p->selector}->{$p->name}"] = $p;
		else
			return false;
		return true;
	}
	/**
	 * Deregister a dependant.
	 * Used when the previously dependant value is changed to no longer include this one.
	 * @param PCP_Property $p Property to remove from dependant list
	 * @returns bool True when property is removed, false when property wasn't there
	 */
	private function remove_dependant(&$p)
	{
		if(isset($this->dependants["{$p->selector}->{$p->name}"]))
		{
			$this->dependants["{$p->selector}->{$p->name}"] = null;
			return true;
		} else
			return false;
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
		 * @param null|bool new_value Set $changed to a specified value
		 * @returns bool
		 */
		function changed($new_value = null)
		{
			if($new_value !== null)
				$this->changed = $new_value;

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
	 * Move a property to a new selector
	 */
	function rebase($new)
	{
		// Invalidate dependencies
		$deps = null;

		// Replace old selector with new in dependants
		$dependants = array();

		foreach($this->dependants as $d)
		{
			// Rename selector
			$new_selector = str_replace($this->selector, $new, $d->selector);
			$d->selector = $new_selector;

			// If the dependant already exists, use that
			if(isset($pcp->state['selectors'][$new_selector]))
				$d = &$pcp->state['selectors'][$new_selecor][$d->name];

			// Propagate downwards
			$d->rebase($new_selector);

			// Add to new dependants list
			$dependants["{$d->selector}->{$d->name}"] = $d;
		}

		// Replace old list of dependants with new list
		$this->dependants = $dependants;

		$this->selector = $new;
	}
		/**
		 * @returns array Array of @see PCP_Property dependencies
		 */
		private function deps()
		{
			global $pcp;

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
					$n = 1;
					while($n)
					{
						if(isset($pcp->state['selectors'][$scope][$splitdep[3]]))
						{
							$this->deps[$dep] = $pcp->state['selectors'][$scope][$splitdep[3]];
							$scope = '';
						} else
							$scope = preg_replace('/[ >+].*$/', '', $scope, 1, $n);
					}
				} else
				{
					// Selector->Property
					
					if(!($this->deps[$dep] = $pcp->state['selectors'][$splitdep[1]][$splitdep[2]]))
					{
						trigger_error(
							  "{$this->src}:{$this->ln}:{$this->cn}: "
							 ."Reference made to undeclared property '{$splitdep[2]}' "
							 ."in '{$splitdep[1]}'"
							, E_USER_ERROR
						);
						exit(-1);
					}
				}
			}

			// Register ourselves as a dependant on each dependency
			foreach($this->deps as $dep)
				$dep->add_dependant($this);

			return $this->deps;
		}
	}
?>
