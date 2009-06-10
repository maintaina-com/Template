<?php
/**
 * Horde Template system. Adapted from bTemplate by Brian Lozier
 * <brian@massassi.net>.
 *
 * Horde_Template provides a basic template engine with tags, loops,
 * and if conditions. However, it is also a simple interface with
 * several essential functions: set(), fetch(), and
 * parse(). Subclasses or decorators can implement (or delegate) these
 * three methods, plus the options api, and easily implement other
 * template engines (PHP code, XSLT, etc.) without requiring usage
 * changes.
 *
 * Copyright 2002-2009 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (LGPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/lgpl.html.
 *
 * @author  Chuck Hagenbuch <chuck@horde.org>
 * @author  Michael Slusarz <slusarz@horde.org>
 * @package Horde_Template
 */
class Horde_Template
{
    /**
     * Option values.
     *
     * @var array
     */
    protected $_options = array();

    /**
     * Directory that templates should be read from.
     *
     * @var string
     */
    protected $_basepath = '';

    /**
     * Tag (scalar) values.
     *
     * @var array
     */
    protected $_scalars = array();

    /**
     * Loop tag values.
     *
     * @var array
     */
    protected $_arrays = array();

    /**
     * Cloop tag values.
     *
     * @var array
     */
    protected $_carrays = array();

    /**
     * If tag values.
     *
     * @var array
     */
    protected $_ifs = array();

    /**
     * Name of cached template file.
     *
     * @var string
     */
    protected $_templateFile = null;

    /**
     * Cached source of template file.
     *
     * @var string
     */
    protected $_template = null;

    /**
     * Constructor. Can set the template base path and whether or not
     * to drop template variables after a parsing a template.
     *
     * @param string  $basepath  The directory where templates are read from.
     */
    public function __construct($basepath = null)
    {
        if (!is_null($basepath)) {
            $this->_basepath = $basepath;
        }
    }

    /**
     * Sets an option.
     *
     * @param string $option  The option name.
     * @param mixed  $val     The option's value.
     */
    public function setOption($option, $val)
    {
        $this->_options[$option] = $val;
    }

    /**
     * Set the template contents to a string.
     *
     * @param string $template  The template text.
     */
    public function setTemplate($template)
    {
        $this->_template = $template;
        $this->_templateFile = 'string';
    }

    /**
     * Returns an option's value.
     *
     * @param string $option  The option name.
     *
     * @return mixed  The option's value.
     */
    public function getOption($option)
    {
        return isset($this->_options[$option])
            ? $this->_options[$option]
            : null;
    }

    /**
     * Sets a tag, loop, or if variable.
     *
     * @param string|array $tag   Either the tag name or a hash with tag names
     *                            as keys and tag values as values.
     * @param mixed $var          The value to replace the tag with.
     * @param boolean $isIf       Is this for an <if:> tag? (Default: no).
     */
    public function set($tag, $var, $isIf = false)
    {
        if (is_array($tag)) {
            foreach ($tag as $tTag => $tVar) {
                $this->set($tTag, $tVar, $isIf);
            }
        } elseif (is_array($var) || is_object($var)) {
            $this->_arrays[$tag] = $var;
            if ($isIf) {
                // Just store the same variable that we stored in
                // $this->_arrays - if we don't modify it, PHP's
                // reference counting ensures we're not using any
                // additional memory here.
                $this->_ifs[$tag] = $var;
            }
        } else {
            $this->_scalars[$tag] = $var;
            if ($isIf) {
                // Just store the same variable that we stored in
                // $this->_scalars - if we don't modify it, PHP's
                // reference counting ensures we're not using any
                // additional memory here.
                $this->_ifs[$tag] = $var;
            }
        }
    }

    /**
     * Sets values for a cloop.
     *
     * @param string $tag   The name of the cloop.
     * @param array $array  The values for the cloop.
     * @param array $cases  The cases (test values) for the cloops.
     */
    public function setCloop($tag, $array, $cases)
    {
        $this->_carrays[$tag] = array(
            'array' => $array,
            'cases' => $cases,
        );
    }

    /**
     * Returns the value of a tag or loop.
     *
     * @param string $tag  The tag name.
     *
     * @return mixed  The tag value or null if the tag hasn't been set yet.
     */
    public function get($tag)
    {
        if (isset($this->_arrays[$tag])) {
            return $this->_arrays[$tag];
        } elseif (isset($this->_scalars[$tag])) {
            return $this->_scalars[$tag];
        }
        return null;
    }

    /**
     * Fetches a template from the specified file and return the parsed
     * contents.
     *
     * @param string $filename  The file to fetch the template from.
     *
     * @return string  The parsed template.
     * @throws Horde_Exception
     */
    public function fetch($filename)
    {
        $contents = $this->_getTemplate($filename);

        // Parse and return the contents.
        return $this->parse($contents);
    }

    /**
     * Parses all variables/tags in the template.
     *
     * @param string $contents  The unparsed template.
     *
     * @return string  The parsed template.
     */
    public function parse($contents = null)
    {
        if (is_null($contents)) {
            $contents = $this->_template;
        }

        // Process ifs.
        if (!empty($this->_ifs)) {
            foreach (array_keys($this->_ifs) as $tag) {
                $contents = $this->_parseIf($tag, $contents);
            }
        }

        // Process tags.
        $replace = $search = array();
        reset($this->_scalars);
        while (list($key, $value) = each($this->_scalars)) {
            $search[] = $this->_getTag($key);
            $replace[] = $value;
        }
        if (count($search)) {
            $contents = str_replace($search, $replace, $contents);
        }

        // Process cloops.
        reset($this->_carrays);
        while (list($key, $array) = each($this->_carrays)) {
            $contents = $this->_parseCloop($key, $array, $contents);
        }

        // Parse gettext tags, if the option is enabled.
        if ($this->getOption('gettext')) {
            $contents = $this->_parseGettext($contents);
        }

        // Process loops and arrays.
        reset($this->_arrays);
        while (list($key, $array) = each($this->_arrays)) {
            $contents = $this->_parseLoop($key, $array, $contents);
        }

        // Return parsed template.
        return $contents;
    }

    /**
     * Returns full start and end tags for a named tag.
     *
     * @param string $tag        The name of the tag.
     * @param string $directive  The kind of tag [tag, if, loop, cloop].
     *
     * @return array  'b' => Start tag, 'e' => End tag.
     */
    protected function _getTags($tag, $directive)
    {
        return array(
            'b' => '<' . $directive . ':' . $tag . '>',
            'e' => '</' . $directive . ':' . $tag . '>'
        );
    }

    /**
     * Formats a scalar tag (default format is <tag:name>).
     *
     * @param string $tag  The name of the tag.
     *
     * @return string  The full tag with the current start/end delimiters.
     */
    protected function _getTag($tag)
    {
        return '<tag:' . $tag . ' />';
    }

    /**
     * Extracts a portion of a template.
     *
     * @param array $t           The tag to extract. Hash format is:
     *                             $t['b'] - The start tag
     *                             $t['e'] - The end tag
     * @param string &$contents  The template to extract from.
     */
    protected function _getStatement($t, &$contents)
    {
        // Locate the statement.
        $pos = strpos($contents, $t['b']);
        if ($pos === false) {
            return false;
        }

        $tag_length = strlen($t['b']);
        $fpos = $pos + $tag_length;
        $lpos = strpos($contents, $t['e']);
        $length = $lpos - $fpos;

        // Extract & return the statement.
        return substr($contents, $fpos, $length);
    }

    /**
     * Parses gettext tags.
     *
     * @param string $contents  The unparsed content of the file.
     *
     * @return string  The parsed contents of the gettext blocks.
     */
    protected function _parseGettext($contents)
    {
        // Get the tags & loop.
        $t = array(
            'b' => '<gettext>',
            'e' => '</gettext>'
        );

        while ($text = $this->_getStatement($t, $contents)) {
            $contents = str_replace($t['b'] . $text . $t['e'], _($text), $contents);
        }

        return $contents;
    }

    /**
     * Parses a given if statement.
     *
     * @param string $tag       The name of the if block to parse.
     * @param string $contents  The unparsed contents of the if block.
     *
     * @return string  The parsed contents of the if block.
     */
    protected function _parseIf($tag, $contents, $key = null)
    {
        // Get the tags & if statement.
        $t = $this->_getTags($tag, 'if');
        $et = $this->_getTags($tag, 'else');

        // explode the tag, so we have the correct keys for the array
        if (isset($key)) {
            list($tg, $k) = explode('.', $tag);
        }
        while (($if = $this->_getStatement($t, $contents)) !== false) {
            // Check for else statement.
            if ($else = $this->_getStatement($et, $if)) {
                // Process the if statement.
                $replace = ((isset($key) && $this->_ifs[$tg][$key][$k]) || (isset($this->_ifs[$tag]) && $this->_ifs[$tag]))
                    ? str_replace($et['b'] . $else . $et['e'], '', $if)
                    : $else;
            } else {
                // Process the if statement.
                $replace = isset($key)
                    ? ($this->_ifs[$tg][$key][$k] ? $if : null)
                    : ($this->_ifs[$tag] ? $if : null);
            }

            // Parse the template.
            $contents = str_replace($t['b'] . $if . $t['e'], $replace, $contents);
        }

        // Return parsed template.
        return $contents;
    }

    /**
     * Parses the given array for any loops or other uses of the array.
     *
     * @param string $tag       The name of the loop to parse.
     * @param array  $array     The values for the loop.
     * @param string $contents  The unparsed contents of the loop.
     *
     * @return string  The parsed contents of the loop.
     */
    protected function _parseLoop($tag, $array, $contents)
    {
        // Get the tags & loop.
        $t = $this->_getTags($tag, 'loop');
        $loop = $this->_getStatement($t, $contents);

        // See if we have a divider.
        $l = $this->_getTags($tag, 'divider');
        $divider = $this->_getStatement($l, $loop);
        $contents = str_replace($l['b'] . $divider . $l['e'], '', $contents);

        // Process the array.
        do {
            $parsed = '';
            $first = true;
            reset($array);
            while (list($key, $value) = each($array)) {
                if (is_array($value) || is_object($value)) {
                    $i = $loop;
                    reset($value);
                    while (list($key2, $value2) = each($value)) {
                        if (!is_array($value2) && !is_object($value2)) {
                            // Replace associative array tags.
                            $aa_tag = $tag . '.' . $key2;
                            $i = str_replace($this->_getTag($aa_tag), $value2, $i);
                            $pos = strpos($tag, '.');
                            if (($pos !== false) &&
                                !empty($this->_ifs[substr($tag, 0, $pos)])) {
                                $this->_ifs[$aa_tag] = $value2;
                                $i = $this->_parseIf($aa_tag, $i);
                                unset($this->_ifs[$aa_tag]);
                            }
                        } else {
                            // Check to see if it's a nested loop.
                            $i = $this->_parseLoop($tag . '.' . $key2, $value2, $i);
                        }
                    }
                    $i = str_replace($this->_getTag($tag), $key, $i);
                } elseif (is_string($key) && !is_array($value) && !is_object($value)) {
                    $contents = str_replace($this->_getTag($tag . '.' . $key), $value, $contents);
                } elseif (!is_array($value) && !is_object($value)) {
                    $i = str_replace($this->_getTag($tag . ''), $value, $loop);
                } else {
                    $i = null;
                }

                // Parse conditions in the array.
                if (!empty($this->_ifs[$tag][$key]) &&
                    is_array($this->_ifs[$tag][$key]) &&
                    $this->_ifs[$tag][$key]) {
                    reset($this->_ifs[$tag][$key]);
                    foreach (array_keys($this->_ifs[$tag][$key]) as $cTag) {
                        $i = $this->_parseIf($tag . '.' . $cTag, $i, $key);
                    }
                }

                // Add the parsed iteration.
                if (isset($i)) {
                    // If it's not the first time through, prefix the
                    // loop divider, if there is one.
                    if (!$first) {
                        $i = $divider . $i;
                    }
                    $parsed .= rtrim($i);
                }

                // No longer the first time through.
                $first = false;
            }

            // Replace the parsed pieces of the template.
            $contents = str_replace($t['b'] . $loop . $t['e'], $parsed, $contents);
        } while ($loop = $this->_getStatement($t, $contents));

        return $contents;
    }

    /**
     * Parses the given case loop (cloop).
     *
     * @param string $tag       The name of the cloop to parse.
     * @param array  $array     The values for the cloop.
     * @param string $contents  The unparsed contents of the cloop.
     *
     * @return string  The parsed contents of the cloop.
     */
    protected function _parseCloop($tag, $array, $contents)
    {
        // Get the tags & cloop.
        $t = $this->_getTags($tag, 'cloop');

        while ($loop = $this->_getStatement($t, $contents)) {
            // Set up the cases.
            $array['cases'][] = 'default';
            $case_content = array();

            // Get the case strings.
            foreach ($array['cases'] as $case) {
                $ctags[$case] = $this->_getTags($case, 'case');
                $case_content[$case] = $this->_getStatement($ctags[$case], $loop);
            }

            // Process the cloop.
            $parsed = '';
            reset($array['array']);
            while (list($key, $value) = each($array['array'])) {
                if (is_numeric($key) &&
                    (is_array($value) || is_object($value))) {
                    // Set up the cases.
                    $current_case = isset($value['case'])
                        ? $value['case']
                        : 'default';
                    unset($value['case']);
                    $i = $case_content[$current_case];

                    // Loop through each value.
                    reset($value);
                    while (list($key2, $value2) = each($value)) {
                        $i = (is_array($value2) || is_object($value2))
                            ? $this->_parseLoop($tag . '.' . $key2, $value2, $i)
                            : str_replace($this->_getTag($tag . '.' . $key2), $value2, $i);
                    }
                }

                // Add the parsed iteration.
                $parsed .= rtrim($i);
            }

            // Parse the cloop.
            $contents = str_replace($t['b'] . $loop . $t['e'], $parsed, $contents);
        }

        return $contents;
    }

    /**
     * Fetch the contents of a template into $this->_template; cache
     * the filename in $this->_templateFile.
     *
     * @param string $filename  Location of template file on disk.
     *
     * @return string  The loaded template content.
     * @throws Horde_Exception
     */
    protected function _getTemplate($filename = null)
    {
        if (!is_null($filename) && ($filename != $this->_templateFile)) {
            $this->_template = null;
        }

        if (!is_null($this->_template)) {
            return $this->_template;
        }

        // Get the contents of the file.
        $file = $this->_basepath . $filename;
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new Horde_Exception(sprintf(_("Template \"%s\" not found."), $file));
        }

        $this->_template = $contents;
        $this->_templateFile = $filename;

        return $this->_template;
    }

}
