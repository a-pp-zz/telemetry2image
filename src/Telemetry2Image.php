<?php
/**
 * Telemetry2Image
 * @package GeoTools/Telemetry2Image
 * @version 1.0.0
 */
namespace AppZz\GeoTools;
use \AppZz\Helpers\Arr;
use \AppZz\Tools\ExifTool;
use \Imagick;
use \ImagickDraw;
use \ImagickPixel;

class Telemetry2Image {

    private $_input;
    private $_output;
    private $_fonts = [];
    private $_values = [];
    private $_exif;

    public function __construct ($input, $overwrite = false)
    {
        $this->_input = $input;

        if ($overwrite) {
            $this->_output = $input;
        }
    }

    public function output ($output = null, $suffix = '-output')
    {
        if ( ! empty ($output)) {
            $this->_output = $output;
        } else {
            $pi = pathinfo ($this->_input);
            $this->_output = sprintf ('%s/%s%s.%s', $pi['dirname'], $pi['filename'], $suffix, $pi['extension']);
        }

        return $this;
    }

    public function font ($type, $path, $size, $color)
    {
        $this->_fonts[$type] = ['path'=>$path, 'size'=>$size, 'color'=>$color];
        return $this;
    }

    public function add_value ($key, $label = null)
    {
        $value = Arr::get ($this->_exif, $key);

        if ( ! empty ($value) AND is_scalar ($value)) {
            $label = ! empty ($label) ? $label : $key;

            if (strpos ($label, 'exif:') !== false) {
                $label = str_replace ('exif:', '', $label);
                $label = Arr::get ($this->_exif, $label, $label);
            }

            $this->_values[] = ['value'=>$value, 'label'=>$label];
        }

        return $this;
    }

    public function prepare ()
    {
        $tool = ExifTool::factory ($this->_input)->analyze();
        $this->_exif = $tool->get_result ();

        if ( ! empty ($this->_exif['RelativeAltitude'])) {
            $this->_exif['RelativeAltitude'] = str_replace ('+', '', $this->_exif['RelativeAltitude']);
        }

        if ( ! empty ($this->_exif['DateTimeOriginal'])) {
            list ($date, $time) = explode (' ', $this->_exif['DateTimeOriginal']);
            $dt = \DateTime::createFromFormat ('Y:m:d', $date);
            $date = $dt->format ('d/m/Y');
            $this->_exif['date'] = $date;
            $this->_exif['time'] = $time;
        }

        return ! empty ($this->_exif);
    }

    public function get_exif ()
    {
        return $this->_exif;
    }

    private function _set_font ($type, &$draw)
    {
        $font = Arr::get ($this->_fonts, $type);

        if ($font) {
            $draw->setFont(Arr::get($font, 'path'));
            $draw->setFontSize(Arr::get($font, 'size'));
            $draw->setFillColor(new ImagickPixel(Arr::get($font, 'color')));
        }
    }

    private function _get_text_layout ($text, $image, $draw)
    {
        $metrics = $image->queryFontMetrics($draw, $text);
        $baseline = Arr::path ($metrics, 'boundingBox.y2');
        $width = Arr::get($metrics, 'textWidth') + (2 * Arr::path($metrics, 'boundingBox.x1'));
        $height = Arr::get($metrics, 'textHeight') + Arr::get($metrics, 'descender');
        return ['baseline'=>$baseline, 'width'=>$width, 'height'=>$height];
    }

    public function save_image ($position = 'top-right', array $layout = [])
    {
        if ( ! $this->_output) {
            $this->output (null, '-overlay-'.$position);
        }

        $default_layout = [
            'text_padding' => 0,
            'padding' => 0,
            'spacer' => 100,
            'bg' => 'none',
            'opacity_impl' => 0
        ];

        $layout = array_merge ($default_layout, $layout);
        extract($layout);

        $src_image = new Imagick ($this->_input);
        $src_width = $src_image->getImageWidth();
        $src_height = $src_image->getImageHeight();

        $overlay = new Imagick;
        $overlay->newImage($src_image->getImageWidth(), $src_image->getImageHeight(), new ImagickPixel($bg));
        $overlay->setImageFormat('png');

        if ($bg != 'none' AND $opacity_impl > 1) {
            $overlay->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
            $overlay->evaluateImage(Imagick::EVALUATE_DIVIDE, $opacity_impl, Imagick::CHANNEL_ALPHA);
        }

        $draw = new ImagickDraw();
        $draw->setTextAntialias(true);
        $draw->setTextAlignment(Imagick::ALIGN_LEFT);
        $draw->setGravity(Imagick::GRAVITY_NORTHWEST);
        $overlay_height = $text_padding;
        $widths = [];

        foreach ($this->_values as $num=>$value) {
            $this->_set_font ('big', $draw);
            $text_layout = $this->_get_text_layout ($value['value'], $overlay, $draw);
            $overlay_height += Arr::get($text_layout, 'height');
            $widths[] = Arr::get($text_layout, 'width');
            $overlay->annotateImage($draw, $text_padding, $overlay_height, 0, $value['value']);

            $this->_set_font ('small', $draw);
            $text_layout = $this->_get_text_layout ($value['label'], $overlay, $draw);
            $overlay_height += Arr::get($text_layout, 'height');
            $widths[] = Arr::get($text_layout, 'width');
            $overlay->annotateImage($draw, $text_padding, $overlay_height, 0, $value['label']);

            if (($num + 1) !== count ($this->_values)) {
                $overlay_height += $spacer;
            } else {
                $overlay_height += Arr::get($text_layout, 'baseline') + $text_padding;
            }
        }

        $overlay_width  = max($widths) + (2 * $text_padding);
        $overlay->cropImage ($overlay_width, $overlay_height, 0, 0);

        switch ($position) {
            case 'top-right':
                $x = $src_width - $overlay_width - $padding;
                $y = $padding;
            break;

            case 'top-center':
                $x = intval (($src_width - $overlay_width)/2);
                $y = $padding;
            break;

            case 'center':
                $x = intval (($src_width - $overlay_width)/2);
                $y = intval (($src_height - $overlay_height)/2);
            break;

            case 'left-center':
                $x = $padding;
                $y = intval (($src_height - $overlay_height)/2);
            break;

            case 'right-center':
                $x = $src_width - $overlay_width - $padding;
                $y = intval (($src_height - $overlay_height)/2);
            break;

            case 'bottom-left':
                $x = $padding;
                $y = $src_height - $overlay_height - $padding;
            break;

            case 'bottom-right':
                $x = $src_width - $overlay_width - $padding;
                $y = $src_height - $overlay_height - $padding;
            break;

            case 'bottom-center':
                $x = intval (($src_width - $overlay_width)/2);
                $y = $src_height - $overlay_height - $padding;
            break;

            default:
                $x = $y = $padding;
            break;
        }


        $src_image->compositeImage($overlay, Imagick::COMPOSITE_OVER, $x, $y);
        $result = $src_image->writeImage($this->_output);
        $src_image->clear();
        $overlay->clear();
        $this->_output = null;
        return $result;
    }
}
