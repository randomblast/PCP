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
					$fstate = array();		// Per-file state. @see $state

					while(false !== ($c = fgetc($fd)))
					{
						switch($c)
						{
							case '{':	// selector { properties

								// Convert buffer into selector, then clear
								if(false === ($sel = $this->clean_selector($buf))) fclose($fd);
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
												 ."Assumed '{$p->name}: $buf'"
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
									trigger_error(
										  "$src:$ln:$sc: Found ':' in property value"
										, E_USER_WARNING
									);
								else
								{
									$p->src = $src;
									$p->ln = $ln;
									$p->cn = $cn;

									$fstate[end($selector)][$p->name] = &$p;
								}

								break;

							case ';':	// property value ;

								if($p)
								{
									$p->value = trim($buf);
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
		}

		/// Write javascript engine
		function js()
		{
		}
		/// Validate selector and prepare for use as a hash
		function clean_selector($sel)
		{
			// TODO Do this properly
			return $sel;
		}
	}

	class PCP_Property
	{
		var $name;
		var $value;
		var $src;
		var $ln;
		var $cn;
	}
	/**
	 * Get or set a single property or variable in a selector
	 */
	function pcp($selector, $property, $value = null)
	{
	}
?>
