<?php
/**
 * IDPay payment plugin
 *
 * @developer JMDMahdi
 * @publisher IDPay
 * @package VirtueMart
 * @subpackage payment
 * @copyright (C) 2018 IDPay
 * @license http://www.gnu.org/licenses/gpl-2.0.html GPLv2 or later
 *
 * http://idpay.ir
 */
defined('_JEXEC') or die('Restricted access');
?>

<form action="<?php echo @$vars->idpay; ?>" method="get" name="adminForm" enctype="multipart/form-data">
	<p><?php echo 'درگاه IDPay' ?></p>
	<br />
    <input type="submit" class="j2store_cart_button button btn btn-primary" value="<?php echo JText::_($vars->button_text); ?>" />
</form>
