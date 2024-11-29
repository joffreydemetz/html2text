<?php
/**
 * (c) Joffrey Demetz <joffrey.demetz@gmail.com>
 * 
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace JDZ\Utils;

/**
 * HTML to Text
 * 
 * @author Joffrey Demetz <joffrey.demetz@gmail.com>
 */
class HtmlToText 
{
	protected array $defaultOptions = [
    'ignore_errors' => false,
    'drop_links' => false,
    'char_set' => 'auto'
  ];
  
	protected array $nbspCodes = [
    "\xc2\xa0",
    "\u00a0",
  ];
  
	protected array $zwnjCodes = [
    "\xe2\x80\x8c",
    "\u200c",
  ];
  
	public function convert(string $html, array $options=[]): string
  {
		$options = array_merge($this->defaultOptions, $options);

		foreach($options as $key => $value){
			if ( !in_array($key, array_keys($this->defaultOptions)) ){
				throw new \InvalidArgumentException("Unknown html2text option '$key'. Valid options are " . implode(',', $this->defaultOptions));
			}
		}
    
		if ( true === ($is_office_document=$this->isOfficeDocument($html))  ){
			$html = str_replace(["<o:p>", "</o:p>"], "", $html);
		}
    
		$html = $this->fixNewlines($html);

		// legacy versions of php < 8.1
		if ( \PHP_MAJOR_VERSION * 10 + \PHP_MINOR_VERSION < 81 && \mb_detect_encoding($html, "UTF-8", true) ){
			$html = \mb_convert_encoding($html, "HTML-ENTITIES", "UTF-8");
		}
    
		$doc = $this->getDocument($html, $options);

		$output = $this->iterateOverNode($doc, null, false, $is_office_document, $options);
    
		$output = $this->processWhitespaceNewlines($output);

		return $output;
	}

	protected function fixNewlines(string $text): string
  {
		// replace \r\n to \n
		$text = str_replace("\r\n", "\n", $text);
		
    // remove \rs
		$text = str_replace("\r", "\n", $text);
    
    return $text;
  }

	protected function processWhitespaceNewlines(string $text): string
  {
		// remove excess spaces around tabs
		$text = preg_replace("/ *\t */im", "\t", $text);

		// remove leading whitespace
		$text = ltrim($text);

		// remove leading spaces on each line
		$text = preg_replace("/\n[ \t]*/im", "\n", $text);

		// convert non-breaking spaces to regular spaces to prevent output issues,
		// do it here so they do NOT get removed with other leading spaces, as they
		// are sometimes used for indentation
		$text = $this->renderText($text);

		// remove trailing whitespace
		$text = rtrim($text);

		// remove trailing spaces on each line
		$text = preg_replace("/[ \t]*\n/im", "\n", $text);

		// unarmor pre blocks
		$text = $this->fixNewLines($text);

		// remove unnecessary empty lines
		$text = preg_replace("/\n\n\n*/im", "\n\n", $text);

		return $text;
	}

	protected function isOfficeDocument(string $html): bool
  {
		return strpos($html, "urn:schemas-microsoft-com:office") !== false;
	}

	protected function isWhitespace(string $text): bool
  {
		return strlen(trim($this->renderText($text), "\n\r\t ")) === 0;
	}

	/**
	 * @throws \Exception if the HTML could not be loaded as a {@link \DOMDocument}
	 */
	protected function getDocument(string $html, array $options): \DOMDocument
  {
		$html = trim($html);
    
		$doc = new \DOMDocument();
    
		if ( !$html ){
			// DOMDocument doesn't support empty value and throws an error
			// Return empty document instead
			return $doc;
		}
    
    // HTML does not begin with a tag
		if ( '<' !== substr($html, 0, 1) ){
			$html = '<body>' . $html . '</body>';
		}
    
		$header = '';
    
		// use char sets for modern versions of php
		if ( \PHP_MAJOR_VERSION * 10 + \PHP_MINOR_VERSION >= 81 ){
			// use specified char_set, or auto detect if not set
			$char_set = !empty($options['char_set']) ? $options['char_set'] : 'auto';
			
      if ( 'auto' === $char_set ){
				$char_set = \mb_detect_encoding($html);
			} 
      elseif ( strpos($char_set, ',') ){
				\mb_detect_order($char_set);
				$char_set = mb_detect_encoding($html);
			}
			// turn off error detection for Windows-1252 legacy html
			if ( strpos($char_set, '1252') ){
				$options['ignore_errors'] = true;
			}
      
			$header = '<?xml version="1.0" encoding="'.$char_set.'">';
		}
    
		if ( !empty($options['ignore_errors']) ){
			$doc->strictErrorChecking = false;
			$doc->recover = true;
			$doc->xmlStandalone = true;
			$old_internal_errors = \libxml_use_internal_errors(true);
			$load_result = $doc->loadHTML($header . $html, \LIBXML_NOWARNING | \LIBXML_NOERROR | \LIBXML_NONET | \LIBXML_PARSEHUGE);
			\libxml_use_internal_errors($old_internal_errors);
		}
		else {
			$load_result = $doc->loadHTML($header . $html);
		}

		if ( !$load_result ){
			throw new \Exception("Could not load HTML - badly formed?\n\n".$html);
		}
    
		return $doc;
	}
  
	/**
	 * Replace any special characters with simple text versions, to prevent output issues:
	 * - Convert non-breaking spaces to regular spaces; and
	 * - Convert zero-width non-joiners to '' (nothing).
	 */
	protected function renderText(string $text): string
  {
		$text = str_replace($this->nbspCodes, " ", $text);
		$text = str_replace($this->zwnjCodes, "", $text);
		return $text;
	}
  
	protected function nextChildName(?\DOMNode $node): ?string
  {
		$nextNode = $node->nextSibling;
		while(null!==$nextNode){
			if ($nextNode instanceof \DOMText ){
				if ( !$this->isWhitespace($nextNode->wholeText) ){
					break;
				}
			}

			if ( $nextNode instanceof \DOMElement ){
				break;
			}

			$nextNode = $nextNode->nextSibling;
		}

		$nextName = null;
		if ( ($nextNode instanceof \DOMElement || $nextNode instanceof \DOMText) && null !== $nextNode ){
			$nextName = strtolower($nextNode->nodeName);
		}
    
		return $nextName;
	}

	protected function iterateOverNode(\DOMNode $node, ?string $prevName, bool $in_pre, bool $is_office_document, array $options): string
  {
		if ($node instanceof \DOMText ){
		  // Replace whitespace characters with a space (equivilant to \s)
			if ($in_pre ){
				$text = "\n" . trim($this->renderText($node->wholeText), "\n\r\t ") . "\n";

				// Remove trailing whitespace only
				$text = preg_replace("/[ \t]*\n/im", "\n", $text);

				// armor newlines with \r.
				return str_replace("\n", "\r", $text);

			}
			$text = $this->renderText($node->wholeText);
			$text = preg_replace("/[\\t\\n\\f\\r ]+/im", " ", $text);

			if (!$this->isWhitespace($text) && ($prevName == 'p' || $prevName == 'div') ){
				return "\n" . $text;
			}
			return $text;
		}

		if ($node instanceof \DOMDocumentType || $node instanceof \DOMProcessingInstruction ){
			// ignore
			return "";
		}

		$name = strtolower($node->nodeName);
		$nextName = $this->nextChildName($node);

		// start whitespace
		switch ($name ){
			case "hr":
				$prefix = '';
				if ($prevName != null ){
					$prefix = "\n";
				}
				return $prefix . "---------------------------------------------------------------\n";

			case "style":
			case "head":
			case "title":
			case "meta":
			case "script":
				// ignore these tags
				return "";

			case "h1":
			case "h2":
			case "h3":
			case "h4":
			case "h5":
			case "h6":
			case "ol":
			case "ul":
			case "pre":
				// add two newlines
				$output = "\n\n";
				break;

			case "td":
			case "th":
				// add tab char to separate table fields
			   $output = "\t";
			   break;

			case "p":
				// Microsoft exchange emails often include HTML which, when passed through
				// html2text, results in lots of double line returns everywhere.
				//
				// To fix this, for any p element with a className of `MsoNormal` (the standard
				// classname in any Microsoft export or outlook for a paragraph that behaves
				// like a line return) we skip the first line returns and set the name to br.
				// @phpstan-ignore-next-line
				if ($is_office_document && $node->getAttribute('class') == 'MsoNormal' ){
					$output = "";
					$name = 'br';
					break;
				}

				// add two lines
				$output = "\n\n";
				break;

			case "tr":
				// add one line
				$output = "\n";
				break;

			case "div":
				$output = "";
				if ($prevName !== null ){
					// add one line
					$output .= "\n";
				}
				break;

			case "li":
				$output = "- ";
				break;

			default:
				// print out contents of unknown tags
				$output = "";
				break;
		}

		// debug
		//$output .= "[$name,$nextName]";

		if (isset($node->childNodes) ){

			$n = $node->childNodes->item(0);
			$previousSiblingNames = [];
			$previousSiblingName = null;

			$parts = [];
			$trailing_whitespace = 0;

			while ($n != null ){

				$text = $this->iterateOverNode($n, $previousSiblingName, $in_pre || $name == 'pre', $is_office_document, $options);

				// Pass current node name to next child, as previousSibling does not appear to get populated
				if ($n instanceof \DOMDocumentType
					|| $n instanceof \DOMProcessingInstruction
					|| ($n instanceof \DOMText && $this->isWhitespace($text)) ){
					// Keep current previousSiblingName, these are invisible
					$trailing_whitespace++;
				}
				else {
					$previousSiblingName = strtolower($n->nodeName);
					$previousSiblingNames[] = $previousSiblingName;
					$trailing_whitespace = 0;
				}

				$node->removeChild($n);
				$n = $node->childNodes->item(0);

				$parts[] = $text;
			}

			// Remove trailing whitespace, important for the br check below
			while ($trailing_whitespace-- > 0 ){
				array_pop($parts);
			}

			// suppress last br tag inside a node list if follows text
			$last_name = array_pop($previousSiblingNames);
			if ($last_name === 'br' ){
				$last_name = array_pop($previousSiblingNames);
				if ($last_name === '#text' ){
					array_pop($parts);
				}
			}

			$output .= implode('', $parts);
		}

		// end whitespace
		switch($name){
			case "h1":
			case "h2":
			case "h3":
			case "h4":
			case "h5":
			case "h6":
			case "pre":
			case "p":
				// add two lines
				$output .= "\n\n";
				break;

			case "br":
				// add one line
				$output .= "\n";
				break;

			case "div":
				break;

			case "a":
				// links are returned in [text](link) format
				// @phpstan-ignore-next-line
				$href = $node->getAttribute("href");

				$output = trim($output);

				// remove double [[ ]] s from linking images
				if (substr($output, 0, 1) == "[" && substr($output, -1) == "]" ){
					$output = substr($output, 1, strlen($output) - 2);

					// for linking images, the title of the <a> overrides the title of the <img>
					// @phpstan-ignore-next-line
					if ($node->getAttribute("title") ){
						// @phpstan-ignore-next-line
						$output = $node->getAttribute("title");
					}
				}

				// if there is no link text, but a title attr
				// @phpstan-ignore-next-line
				if (!$output && $node->getAttribute("title") ){
					// @phpstan-ignore-next-line
					$output = $node->getAttribute("title");
				}

				if ($href == null ){
					// it doesn't link anywhere
					// @phpstan-ignore-next-line
					if ($node->getAttribute("name") != null ){
						if ($options['drop_links'] ){
							$output = "$output";
						} else {
							$output = "[$output]";
						}
					}
				} else {
					if ($href == $output || $href == "mailto:$output" || $href == "http://$output" || $href == "https://$output" ){
						// link to the same address: just use link
						$output = "$output";
					} else {
						// replace it
						if ($output ){
							if ($options['drop_links'] ){
								$output = "$output";
							} else {
								$output = "[$output]($href)";
							}
						} else {
							// empty string
							$output = "$href";
						}
					}
				}

				// does the next node require additional whitespace?
				switch ($nextName ){
					case "h1": case "h2": case "h3": case "h4": case "h5": case "h6":
						$output .= "\n";
						break;
				}
				break;

			case "img":
				// @phpstan-ignore-next-line
				if ($node->getAttribute("title") ){
					// @phpstan-ignore-next-line
					$output = "[" . $node->getAttribute("title") . "]";
				// @phpstan-ignore-next-line
				} elseif ($node->getAttribute("alt") ){
					// @phpstan-ignore-next-line
					$output = "[" . $node->getAttribute("alt") . "]";
				} else {
					$output = "";
				}
				break;

			case "li":
				$output .= "\n";
				break;

			case "blockquote":
				// process quoted text for whitespace/newlines
				$output = $this->processWhitespaceNewlines($output);

				// add leading newline
				$output = "\n" . $output;

				// prepend '> ' at the beginning of all lines
				$output = preg_replace("/\n/im", "\n> ", $output);

				// replace leading '> >' with '>>'
				$output = preg_replace("/\n> >/im", "\n>>", $output);

				// add another leading newline and trailing newlines
				$output = "\n" . $output . "\n\n";
				break;
			default:
				// do nothing
		}

		return $output;
	}
}