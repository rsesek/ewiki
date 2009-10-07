<?php

function array_flatten($a)
{
    $r = array();
    foreach ($a as $x)
    {
        if (is_array($x))
            $r = array_merge($r, array_flatten($x));
        else
            $r[] = $x;
    }
    return $r;
}

abstract class eMarkup
{
    protected $content;
    protected $ctx;

    public function __construct($content=NULL)
    {
        $this->setContent($content);
    }

    public function setContent($content=NULL)
    {
        $this->content = $content;
    }

    // Parsing bits prototypes {{{1
             protected function fmt_page($blocks)
                       { return implode('', $blocks); }
    abstract protected function fmt_par($s);
    abstract protected function fmt_heading($level, $s);
    abstract protected function fmt_list($items);
    abstract protected function fmt_listitem($s);
             protected function fmt_sublist($items)
                       { return $this->fmt_list($items); }
    abstract protected function fmt_table($rows);
    abstract protected function fmt_row($cells);
    abstract protected function fmt_cell($s);
             protected function fmt_cell_head($s)
                       { return $this->fmt_cell($s); }
             protected function fmt_comment($s)
                       { return ''; }
    abstract protected function fmt_emph($s);
    abstract protected function fmt_strong($s);
    /* Auto-generated vs. user-provided label */
             protected function fmt_link($ref, $label)
                       { return $this->fmt_labeled_link($ref, $label); }
    abstract protected function fmt_labeled_link($ref, $user_label);
             protected function fmt_error($s)
                       { return $this->fmt_plain('[ERROR] '.$s); }
             protected function fmt_image($ref, $width, $height)
                       { return $this->fmt_plain('[IMAGE: '.$ref.']'); }
             protected function fmt_code($s)
                       { return $s; }
    /*
     * fmt_plain() gets unchecked user input. If necessary, it should perform
     * whitespace conversion and escaping of special characters.
     */
             protected function fmt_plain($s) { return $s; }

    static protected function comment_filter($line) // {{{1
    {
        return $line != '#' && (strlen($line) < 2 || $line{0} != '#' || !ctype_space($line{1}));
    }

    protected function error($s) // {{{1
    {
        return $this->fmt_error($this->fmt_plain($s));
    }

    protected function parse_special($in) // {{{1
    {
        $x = preg_split('/\\s+/', substr($in, 1), 2);
        $special = $x[0];
        $args = isset($x[1]) ? $x[1] : '';

        if ($special == 'image')
        {
            if (!preg_match('/^ (.+?) (?: @ (\d+)? x (\d+)? )? $/x', $args, $m))
                return $this->error('Syntax error: '.$in);
            return $this->fmt_image($m[1], empty($m[2]) ? NULL : intval($m[2]), empty($m[3]) ? NULL : intval($m[3]));
        }
        else if ($special == 'code')
        {
            return $this->fmt_code($args);
        }
        else
            return $this->error('Unknown special: '.$in);
    }

    protected function mklink($target, $label=NULL) // {{{1
    {
        if ($label === NULL)
            return $this->fmt_link($target, $target);
        else
            return $this->fmt_labeled_link($target, $label);
    }

    protected function parse_link($in, &$a=0) // {{{1
    {
        $target = '';
        while ($a < strlen($in))
        {
            $b = $a+strcspn($in, '\\|]', $a);
            $target .= substr($in, $a, $b-$a);
            if ($b == strlen($in))
                break;

            if ($in{$b} == '\\')
            {
                $target .= $in{$b+1};
                $a = $b+2;
            }
            else if ($in{$b} == '|')
            {
                $a = $b+1;
                $label = $this->parse_par($in, $a, 'link_label');
                return $this->mklink($target, $label);
            }
            else if (substr($in, $b, 2) == ']]')
            {
                $a = $b+2;
                break;
            }
            else
            {
                $target .= $in{$b};
                $a = $b+1;
            }
        }
        return $this->mklink($target);
    }

    protected function parse_par($in, &$a=0, $ctx='par') // {{{1
    {
        $r = '';
        while ($a < strlen($in))
        {
            if (!preg_match("/ ( ''' | '' | \[\[ | ]] | \| | \\\ ) /x", $in, $m, PREG_OFFSET_CAPTURE, $a))
            {
                $r .= $this->fmt_plain(substr($in, $a));
                $a = strlen($in);
                break;
            }
            list($match, $b) = $m[1];
            $r .= $this->fmt_plain(substr($in, $a, $b-$a));

            $a = $b+strlen($match);
            if ($match == '\\')
            {
                $r .= $this->fmt_plain($in{$a});
                $a++;
                continue;
            }
            else if ($match == "'''")
            {
                if ($ctx == 'strong')
                    return $r;
                $r .= $this->fmt_strong($this->parse_par($in, $a, 'strong'));
                continue;
            }
            else if ($match == "''")
            {
                if ($ctx == 'emph')
                    return $r;
                $r .= $this->fmt_emph($this->parse_par($in, $a, 'emph'));
                continue;
            }
            else if ($match == '[[')
            {
                if ($ctx == 'link_label')
                {
                    /* do not nest links -> reset */
                    $a = $b;
                    return $r;
                }
                $r .= $this->parse_link($in, $a);
                continue;
            }
            else if ($match == ']]')
            {
                if ($ctx == 'link_label')
                    return $r;
            }
            else if ($match == '|')
            {
                if ($ctx == 'cell')
                    return $r;
            }
            /* pass-through for control sequence */
            $r .= $this->fmt_plain($match);
            continue;
        }
        return $r;
    }

    protected function parse_list($in) // {{{1
    {
        $in = explode("\n*", substr($in, 1));
        $items = array();
        for ($i = 0; $i < count($in);)
        {
            /* simple item */
            if ($in[$i]{0} != '*')
            {
                $items[] = $this->fmt_listitem($this->parse_par($in[$i]));
                $i++;
                continue;
            }

            /* sublist */
            for ($j = $i+1; $j < count($in) && $in[$j]{0} == '*'; $j++);

            $lines = array_slice($in, $i, $j-$i);
            $lines = implode("\n", $lines);

            $items[] = $this->fmt_sublist($this->parse_list($lines));

            $i = $j;
        }
        return $items;
    }

    protected function parse_table_row($in, &$a=0) // {{{1
    {
        $cells = array();
        while ($a < strlen($in))
        {
            $ishead = FALSE;
            if ($in{$a} == '=')
            {
                $ishead = TRUE;
                $a++;
            }
            $cell = $this->parse_par($in, $a, 'cell');
            if ($ishead)
                $cell = $this->fmt_cell_head($cell);
            else
                $cell = $this->fmt_cell($cell);
            $cells[] = $cell;
        }
        return $this->fmt_row($cells);
    }

    protected function parse_table($in) // {{{1
    {
        $rows = explode("\n|", substr($in, 1));
        return $this->fmt_table(array_map(array($this, 'parse_table_row'), $rows));
    }

    protected function parse_block($in) // {{{1
    {
        if (empty($in))
            return NULL;
        if ($in{0} == '*')
            return $this->fmt_list($this->parse_list($in));
        if ($in{0} == '|')
            return $this->parse_table($in);
        if ($in{0} == '#')
            return $this->parse_special(trim($in));
        /* heading */
        if (preg_match('/^ (={2,}) \s* (.+?) =* (?:\s|\n)* $/x', $in, $m))
            return $this->fmt_heading(strlen($m[1])-1, $this->parse_par($m[2]));
        return $this->fmt_par($this->parse_par($in));
    }

    protected function parse_page($in) // {{{1
    {
        /* recognize shebang */
        if (substr($in, 0, 2) == '#!')
        {
            return $this->fmt_code($this->fmt_plain($in));
        }

        $lines = explode("\n", $in);
        $lines = array_filter($lines, array('eMarkup', 'comment_filter'));

        $blocks = array();
        $block = '';
        $cur = '';
        foreach ($lines as $line)
        {
            if (empty($line))
            {
                if (!empty($block))
                    $blocks[] = $block;
                $block = '';
                $cur = '';
                continue;
            }
            else if (($line{0} == '=' || $cur == '=') /* "=" starts new block and lasts only 1 line */
                  || ($cur != $line{0} /* one of "#|*" in a block of different type starts a new block */
                   && ($line{0} == '#' || $line{0} == '|' || $line{0} == '*')))
            {
                if (!empty($block))
                    $blocks[] = $block;
                $block = '';
                $cur = $line{0};
            }
            $block .= $line."\n";
        }
        if (!empty($block))
            $blocks[] = $block;

        $blocks = array_map(array($this, 'parse_block'), $blocks);
        $blocks = array_flatten($blocks);
        return $this->fmt_page($blocks);
    }

    public function format() // {{{1
    {
        return $this->parse_page($this->content);
    }
    // }}}1
}

// vim:set fdm=marker fmr={{{,}}}:

?>
