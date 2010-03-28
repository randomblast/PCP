#!/usr/bin/php -q
<?
/**
 * @file pcp.php
 * @package PCP: CSS Preprocessor
 * @version 0.4.4
 * @copyright 2010 Josh Channings <josh+pcp@channings.me.uk>
 * @license LGPLv3
 */

global $pcp;

/**
 * CLI entry point
 */
if(basename($argv[0]) == basename(__FILE__))
{
	global $pcp, $argc, $argv;

	// Get filenames from options
	$diff = ($n = array_search('-d', $argv)) ? $argv[$n + 1] : null;
	$cache = ($n = array_search('-c', $argv)) ? $argv[$n + 1] : null;
	$output = ($n = array_search('-o', $argv)) ? $argv[$n + 1] : null;

	// Help/usage message
	if(
		   in_array('-h', $argv)
		|| in_array('--help', $argv)
		|| $argc == 1
		|| !$output
	){
		echo <<<EOF
PCP: CSS Preprocessor [Copyright 2010 Josh Channings <josh+pcp@channings.me.uk>]

Usage: {$argv[0]} [options] file.pcp file.css ...
Options:
-d	Serialized cache input file (to generate a diff from)
-c	Serialized cache output file
-o	Static CSS output file

EOF;
		exit(0);
	}

	$pcp = new PCP($diff);

	// Add filenames in args to sources list
	foreach($argv as $n => $arg)
	{
		if(
			   $n == 0
			|| $arg == '-o'
			|| $arg == '-c'
			|| $arg == '-d'
		)
			$opt = true;
		else
		{
			if(!$opt)
				$pcp->add_source($arg);

			$opt = false;
		}
	}

	$pcp->parse();

	if($output) file_put_contents($output, $pcp->css(true));
	if($cache)  file_put_contents($cache, $pcp->cache());
}

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
	private $sources = array();			/** @var string $sources */
	public $selectors = array();		/** @var PCP_Selector $selectors */

	/**
	 * @param string $cache Filename of engine cache
	 */
	function __construct($cache = null)
	{
		if(file_exists($cache))
			$this->selectors = unserialize(@file_get_contents($cache));

		// Hook into global
		global $pcp;
		$pcp = &$this;
	}

	/** @param string|array $source .pcp/.css file to add to the list of files to parse */
	function add_source($source)
	{
		if(is_array($source))
		{
			foreach($source as $src)
				if(file_exists($src))
					array_push($this->sources, $src);
		} else
		{
			if(file_exists($source))
				array_push($this->sources, $source);
		}
	}

	/** Clear state, parse sources into state */
	function parse()
	{
		// Clear the state
		$this->selectors = array();

		foreach($this->sources as $src)
		{
			if(false !== ($fd = fopen($src, 'r')))
			{
				$ln = 0; $cn = 0;		// Line number, Column number
				$buf = '';				// Chomped input
				$selector = array();	// Stack of working selectors
				$p = null;				// Current property. @see PCP_Property

				while(false !== ($c = fgetc($fd)))
				{
					switch($c)
					{
						case '{':	// selector { properties

							// Convert buffer into selector, then clear
							$sels = explode(',', trim($buf));
							$buf = '';

							// Add selector to stack
							if(count($selector))
								array_push($selector, end($selector).'>'.PCP::clean_token($sels[0]));
							else
								array_push($selector, PCP::clean_token($sels[0]));

							// Instantiate PCP_Selector for new name
							if(!isset($this->selectors[end($selector)]))
								 new PCP_Selector(
								 	  end($selector)
									, count($selector) > 1 ? $selector[count($selector) - 2] : null
								);

							// Add any comma-delimited selectors as references
							for($i = 1;$i < count($sels);$i++)
								$this->selectors[end($selector)]->add_ref($sels[$i]);
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
								if(isset($p))
								{
									// Check for potential value in the buffer
									if(strlen(trim($buf)))
									{
										trigger_error(
											  "$src:$ln:$sc: Property not closed properly. "
											 ."Assumed '{$p->name}: ".trim($buf).";'"
											, E_USER_WARNING
										);
										// TODO write test case, this isn't actually being handled?

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
							if(isset($p))
							{
								$buf .= ':';
								$cn++;
							} else
							{
								$n = 1;
								while(false !== ($la = fgetc($fd)) && $la != ';' && $la != '{')
									$n++;

								fseek($fd, -$n, SEEK_CUR);

								if($la == '{')
								{
									$buf .= ':';
									break;
								}

								$p = new PCP_Property(
									  end($selector)
									, self::clean_token($buf)
								);

								$p->src = $src;
								$p->ln = $ln;
								$p->cn = $cn;

								$buf = '';
							}

							break;

						case ';':	// property value ; || reference name ;

							if(isset($p))
							{
								$p->set(trim($buf));
								$buf = '';
								unset($p);
							} else
							{
								// Mixin references

								// Check there is a selector to refer from
								if(!count($selector))
								{
									trigger_error(
										  "$src:$ln:$cn: Unexpected ';'"
										, E_USER_ERROR
									);
									fclose($fd);
									break;
								}

								$ref = self::clean_token($buf);
								$buf = '';

								// Make sure the referenced selector exists
								if(!isset($this->selectors[$ref]))
									new PCP_Selector($ref);

								// Add a reference to the current selector
								$this->selectors[$ref]->add_ref(end($selector));
							}
							break;

						case "\n":	// Newline
							$buf .= "\n";
							$ln++;
							$cn = 0;
							break;

						default:	// chomp
							$buf .= $c;
							$cn++;
							break;
					}

				} // while(fgetc())

				fclose($fd);

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
		// Check $base selector exists
		if(!isset($this->selectors[$base]))
			return false;

		// Copy tree
		$this->selectors[$base]->copy($sub);

		// Change properties in new tree
		foreach($delta as $name => $value)
		{
			if(isset($this->selectors[$sub]->properties[$name]))
				$this->selectors[$sub]->properties[$name]->set($value);
			else
				new PCP_Property($sub, $name, $value);
		}
	}
	/** Retrieve a value */
	function get($selector, $property, $strip_units = false)
	{
		if(!isset($this->selectors[$selector])) return null;
		if(!isset($this->selectors[$selector]->properties[$property])) return null;
		if($strip_units)
			return preg_replace(
				  '/(px|em|rad|%)$/'
				, ''
				, $this->selectors[$selector]->properties[$property]->value()
			);
		else
			return $this->selectors[$selector]->properties[$property]->value();
	}
	/** Return a string representing the state of the engine */
	function cache()
	{
		if(count($this->selectors))
			return serialize($this->selectors);
	}

	/**
	 * Generate a string of static CSS
	 * @param bool $diff Should the output be a diff from the last call, or the entire state?
	 * @returns string Minified CSS
	 */
	function css($diff = true)
	{
		ob_start();

		foreach($this->selectors as $sel)
		{
			ob_start();

			// Don't output selectors with no changed properties
			$empty = true;

			echo "{$sel->output_name()}{";

			foreach($sel->properties as $p)
				if($p->name[0] != '$' && (!$diff || $p->changed()))
				{
					echo "{$p->name}:{$p->value(true)};";
					$empty = false;
				}

			echo '}';

			if(!$empty) echo ob_get_clean();
			else ob_end_clean();
		}

		return ob_get_clean();
	}

	/** Write javascript engine */
	function js()
	{
	}
	/** Validate selectors/property names and prepare for use as a hash */
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
				, '/\/\/.*$/m'			// // Comment
			), array(
				  '>'
				, '+'
				, ' '
			), $sel
		);
	}
}

class PCP_Selector
{
	private $primary;				/** @var string $primary Name of this selector */
	private $secs = array();		/** @var array $secs Names of selectors that reference this */
	public $properties = array();	/** @var PCP_Property $properties */
	public $parent = null;			/** @var PCP_Selector $parent */
	public $children = array();		/** @var PCP_Selector $children */

	/** Clean name and attach to global state */
	public function __construct($name, $parent = null)
	{
		global $pcp;

		$this->primary = PCP::clean_token($name);

		// Attach to global list
		$pcp->selectors[$this->primary] = &$this;

		// Attach to parent
		if(null !== $parent && isset($pcp->selectors[$parent]))
		{
			$this->parent = $pcp->selectors[$parent];
			$this->parent->add_child($this);
		}
	}

	/**
	 * Copy this selector's whole tree, with a new name
	 */
	public function copy($new_name, $old_name = null)
	{
		if($old_name === null)
			$old_name = $this->primary;

		$d = new PCP_Selector(str_replace($old_name, $new_name, $this->primary));

		// Copy properties with new selector name
		foreach($this->properties as $p)
			$d->add_property($p->copy($new_name, $old_name));

		// Copy children
		foreach($this->children as $child)
			$d->add_child($child->copy($new_name, $old_name));

		return $d;
	}

	/** Get the primary name of this selector */
	public function name() {return $this->primary;}

	/** Get comma-delimited primary name and references for CSS output */
	public function output_name()
	{
		if(count($this->secs))
			return "{$this->primary},".implode(',', $this->secs);
		else
			return $this->primary;
	}
	/**
	 * Add a reference to this selector from $sel
	 */
	public function add_ref($sel) {array_push($this->secs, PCP::clean_token($sel));}

	/**
	 * Validate a selector and add it to the list of children
	 * @param PCP_Selector $s Reference to the selector to be added
	 * @returns bool False if the name doesn't match $this->name(), true for success
	 */
	public function add_child(&$s)
	{
		// Check the name makes sense
		if($this->name() != substr($s->name(), 0, strlen($this->name())))
			return false;

		$this->children[$s->name()] = $s;

		return true;
	}
	/** */
	public function add_property(&$p)
	{
		$this->properties[$p->name] = $p;
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
	var $dep_values;		/// array Precomputed values from the last changed(bool) set
	var $dependants;		/// array Properties that depend on this. @see PCP_Property
	var $changed;			/// bool Has set() been called since last value()

	function __construct($selector, $name, $value = null)
	{
		global $pcp;

		// TODO Validate these inputs, add error messages
		$this->deps = array();
		$this->dependants = array();
		$this->name = $name;
		$this->selector = PCP::clean_token($selector);

		if($value !== null)
			$this->set($value);

		// Make sure selector exists
		if(!isset($pcp->selectors[$this->selector]))
			new PCP_Selector($this->selector);

		// Attach to selector
		$pcp->selectors[$this->selector]->add_property($this);
	}

	/**
	 * Copy a property with new selector name
	 * @returns PCP_Property Reference to new property
	 */
	function copy($new_selector, $old_selector = null)
	{
		if($old_selector === null)
			$old_selector = $this->selector;

		$p = new PCP_Property(
			  str_replace($old_selector, $new_selector, $this->selector)
			, $this->name
			, $this->value
		);

		// Tell new property it hasn't really changed
		$p->changed(false);

		return $p;
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
				$this->rvalue = str_replace($dep, $p->value(false), $this->rvalue);

		// Reduce maths ops
		$this->rvalue = PCP_Property::compute($this->rvalue);

		// Reset changed indicator and return real value
		if($is_output)
			$this->changed(false);
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
		{
			$this->changed = $new_value;

			// Save current values of dependencies
			if($this->changed === false)
			{
				$this->dep_values = array();

				foreach($this->deps() as $name => $dep)
					$this->dep_values[$name] = $dep->value(false);
			}

			return $this->changed;
		}

		// Check for changed $this->value
		if($this->changed == true)
			return true;

		// Check for changed dependencies
		foreach($this->deps() as $name => $dep)
			if($dep->value() != $this->dep_values[$name])
				return true;

		// Nothing changed
		return false;
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
		preg_match_all('/([\w\.#\+~-]+)->(\$?[\w-]+)|(<*)(\$?[\w-]+)/', $this->value, $matches);

		// Loop through found $ tokens
		foreach($matches[0] as $i => $dep)
		{
			if($matches[1][$i] && $matches[2][$i]) // selector->property form
			{
				if(
					isset($pcp->selectors[$matches[1][$i]]) &&
					isset($pcp->selectors[$matches[1][$i]]->properties[$matches[2][$i]])
				)
					$this->deps[$matches[0][$i]] =
						$pcp->selectors[$matches[1][$i]]->properties[$matches[2][$i]];
				else
					trigger_error(
						  "{$this->src}:{$this->ln}:{$this->cn}: "
						 ."Reference made to undeclared property '{$matches[2][$i]}' "
						 ."in '{$matches[1][$i]}'"
						, E_USER_ERROR
					);
			} else if($matches[4][$i]) // (<)property-name form
			{
				$scope = $this->selector;

				// Remove a selector from the end for each '<' found
				$upshifts = strlen($matches[3][$i]);
				if($upshifts)
				{
					while($upshifts--)
						$scope = preg_replace('/[ >+~].*$/', '', $scope, 1);

					if(
						isset($pcp->selectors[$scope]) &&
						isset($pcp->selectors[$scope]->properties[$matches[4][$i]])
					)
						$this->deps[$matches[0][$i]] =
							$pcp->selectors[$scope]->properties[$matches[4][$i]];
					else
						trigger_error(
						  "{$this->src}:{$this->ln}:{$this->cn}: "
						 ."Reference made to undeclared property '{$matches[4][$i]}' "
						 ."in '{$scope}'"
						, E_USER_ERROR
					);

				} else
				{
					// Broaden scope until we find the named property
					$scope = $this->selector;

					$n = 1;
					while($n)
					{
						if(isset($pcp->selectors[$scope]->properties[$matches[4][$i]]))
						{
							$this->deps[$matches[0][$i]] =
								$pcp->selectors[$scope]->properties[$matches[4][$i]];

							$n = 0;
						} else
							$scope = preg_replace('/[ >+].*$/', '', $scope, 1, $n);
					}
				}
			}
		}

		// Register ourselves as a dependant on each dependency
		foreach($this->deps as $dep)
			$dep->add_dependant($this);

		return $this->deps;
	}
	static function compute($value, $prev_word = null)
	{
		$literal = '([\d\.]+)(px|em|rad|%)?';

		if(false === strpos($value, array('(', '/', '*', '-', '+')) && $prev_word)
			return "$prev_word(".PCP_Property::compute($value).")";

		return preg_replace(
			array(
				  '/([\w-]*)\s*\((.*)\)/e'			// Reduce parentheses
				, "/{$literal}\s*\/\s*{$literal}/e"	// Division
				, "/{$literal}\s*\*\s*{$literal}/e"	// Multiplication
				, "/{$literal}\s*\-\s+{$literal}/e"	// Subtraction
				, "/{$literal}\s*\+\s*{$literal}/e"	// Addition
				, '/\s\s+/'
			), array(
				  'PCP_Property::compute("$2", "$1")'
				, '$3 != 0 ? ($1 / $3)."$2" : null'
				, '($1 * $3)."$2"'
				, '($1 - $3)."$2"'
				, '($1 + $3)."$2"'
				, ' '
			), $value);
	}
}
?>
