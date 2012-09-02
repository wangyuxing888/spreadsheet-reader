<?php
/**
 * Class for parsing ODS files
 *
 * @author Martins Pilsetnieks
 */
	class SpreadsheetReader_ODS implements Iterator, Countable
	{
		private $Options = array(
			'TempDir' => '',
			'ReturnDateTimeObjects' => false
		);

		/**
		 * @var string Path to temporary content file
		 */
		private $ContentPath = '';
		/**
		 * @var XMLReader XML reader object
		 */
		private $Content = false;

		private $Index = 0;

		private $TableOpen = false;
		private $RowOpen = false;

		/**
		 * @param string Path to file
		 * @param array Options:
		 *	TempDir => string Temporary directory path
		 *	ReturnDateTimeObjects => bool True => dates and times will be returned as PHP DateTime objects, false => as strings
		 */
		public function __construct($Filepath, array $Options = null)
		{
			if (!is_readable($Filepath))
			{
				throw new Exception('SpreadsheetReader_ODS: File not readable ('.$Filepath.')');
			}

			$this -> TempDir = isset($Options['TempDir']) && is_writable($Options['TempDir']) ?
				$Options['TempDir'] :
				sys_get_temp_dir();

			$this -> TempDir = rtrim($this -> TempDir, DIRECTORY_SEPARATOR);
			$this -> TempDir = $this -> TempDir.DIRECTORY_SEPARATOR.uniqid().DIRECTORY_SEPARATOR;

			$Zip = new ZipArchive;
			$Status = $Zip -> open($Filepath);

			if ($Status !== true)
			{
				throw new Exception('SpreadsheetReader_ODS: File not readable ('.$Filepath.') (Error '.$Status.')');
			}

			if ($Zip -> locateName('content.xml') !== false)
			{
				$Zip -> extractTo($this -> TempDir, 'content.xml');
				$this -> ContentPath = $this -> TempDir.'content.xml';
			}

			$Zip -> close();

			if ($this -> ContentPath && is_readable($this -> ContentPath))
			{
				$this -> Content = new XMLReader;
				$this -> Content -> open($this -> ContentPath);
				$this -> Valid = true;
			}
		}

		/**
		 * Destructor, destroys all that remains (closes and deletes temp files)
		 */
		public function __destruct()
		{
			if ($this -> Content && $this -> Content instanceof XMLReader)
			{
				$this -> Content -> close();
				unset($this -> Content);
			}
			if (file_exists($this -> ContentPath))
			{
				@unlink($this -> ContentPath);
				unset($this -> ContentPath);
			}
		}

		// !Iterator interface methods
		/** 
		 * Rewind the Iterator to the first element.
		 * Similar to the reset() function for arrays in PHP
		 */ 
		public function rewind()
		{
			if ($this -> Index > 0)
			{
				// If the worksheet was already iterated, XML file is reopened.
				// Otherwise it should be at the beginning anyway
				$this -> Content -> close();
				$this -> Content -> open($this -> ContentPath);
				$this -> Valid = true;

				$this -> TableOpen = false;
				$this -> RowOpen = false;
			}

			$this -> Index = 0;
		}

		/**
		 * Return the current element.
		 * Similar to the current() function for arrays in PHP
		 *
		 * @return mixed current element from the collection
		 */
		public function current()
		{
			if ($this -> Index == 0)
			{
				$this -> next();
				$this -> Index--;
			}
			return $this -> CurrentRow;
		}

		/** 
		 * Move forward to next element. 
		 * Similar to the next() function for arrays in PHP 
		 */ 
		public function next()
		{
			$this -> Index++;

			$this -> CurrentRow = array();

			if (!$this -> TableOpen)
			{
				while ($this -> Valid = $this -> Content -> read())
				{
					if ($this -> Content -> name == 'table:table' && $this -> Content -> nodeType != XMLReader::END_ELEMENT)
					{
						$this -> TableOpen = true;
						break;
					}
				}
			}

			if ($this -> TableOpen && !$this -> RowOpen)
			{
				while ($this -> Valid = $this -> Content -> read())
				{
					switch ($this -> Content -> name)
					{
						case 'table:table':
							$this -> TableOpen = false;
							$this -> Content -> next('office:document-content');
							$this -> Valid = false;
							break 2;
						case 'table:table-row':
							if ($this -> Content -> nodeType != XMLReader::END_ELEMENT)
							{
								$this -> RowOpen = true;
								break 2;
							}
							break;
					}
				}
			}

			if ($this -> RowOpen)
			{
				$LastCellContent = '';

				while ($this -> Valid = $this -> Content -> read())
				{
					switch ($this -> Content -> name)
					{
						case 'table:table-cell':
							if ($this -> Content -> nodeType == XMLReader::END_ELEMENT)
							{
								$this -> CurrentRow[] = $LastCellContent;
							}
							elseif ($this -> Content -> isEmptyElement)
							{
								$LastCellContent = '';
								$this -> CurrentRow[] = '';
							}
							else
							{
								$LastCellContent = '';
							}
						case 'text:p':
							if ($this -> Content -> nodeType != XMLReader::END_ELEMENT)
							{
								$LastCellContent = $this -> Content -> readString();
							}
							break;
						case 'table:table-row':
							$this -> RowOpen = false;
							break 2;
					}
				}
			}

			return $this -> CurrentRow;
		}

		/** 
		 * Return the identifying key of the current element.
		 * Similar to the key() function for arrays in PHP
		 *
		 * @return mixed either an integer or a string
		 */ 
		public function key()
		{
			return $this -> Index;
		}

		/** 
		 * Check if there is a current element after calls to rewind() or next().
		 * Used to check if we've iterated to the end of the collection
		 *
		 * @return boolean FALSE if there's nothing more to iterate over
		 */ 
		public function valid()
		{
			return $this -> Valid;
		}

		// !Countable interface method
		/**
		 * Ostensibly should return the count of the contained items but this just returns the number
		 * of rows read so far. It's not really correct but at least coherent.
		 */
		public function count()
		{
			return $this -> Index + 1;
		}
	}
?>