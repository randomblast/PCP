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
			, 'selectors' => array()
		);

		/**
		 * @param string $cache Filename of engine cache
		 */
		function __construct($cache = null)
		{
			if($cache)
				$state = unserialize(file_get_contents($cache));
		}

		/// @param string|array $source .pcp/.css file to add to the list of files to parse
		function add_source($source)
		{
			if(is_a($source, 'string'))
			{
				if(file_exists($source)) $state['sources'][] = $source;
			} else if(is_a($source, 'array'))
			{
				foreach($source as $src)
					if(file_exists($src)) $state['sources'][] = $src;
			}
		}
		
		/// Clear state, parse sources into state
		function parse()
		{
			// Clear the state
			$state['variables'] = array();
			$state['selectors'] = array();
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
		///
	}
	/**
	 * Get or set a single property or variable in a selector
	 */
	function pcp($selector, $property, $value = null)
	{
	}
?>
