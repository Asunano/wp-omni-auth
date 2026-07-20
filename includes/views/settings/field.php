<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<tr>
    <th scope="row"><?php echo esc_html($label); ?></th>
    <td>
        <?php if ($type === 'toggle') : ?>
            <input type="hidden" name="<?php echo esc_attr($field_name); ?>" value="no">
            <label>
                <input type="checkbox" name="<?php echo esc_attr($field_name); ?>" value="yes"
                    <?php checked($value, 'yes'); ?>>
                <?php echo esc_html($description); ?>
            </label>

        <?php elseif ($type === 'password') : ?>
            <input type="password" name="<?php echo esc_attr($field_name); ?>" value=""
                class="<?php echo esc_attr($css_class); ?>"
                placeholder="<?php echo $has_secret ? esc_attr__('Already configured — leave empty to keep current', 'wp-omni-auth') : ''; ?>">
            <?php if ($has_secret) : ?>
                <p class="description wpomni-success-text">&#10003; <?php esc_html_e('Secret is configured', 'wp-omni-auth'); ?></p>
            <?php endif; ?>

        <?php elseif ($type === 'select') : ?>
            <select name="<?php echo esc_attr($field_name); ?>">
                <?php foreach ($options as $opt_value => $opt_label) : ?>
                    <option value="<?php echo esc_attr($opt_value); ?>" <?php selected($value, $opt_value); ?>>
                        <?php echo esc_html($opt_label); ?>
                    </option>
                <?php endforeach; ?>
            </select>

        <?php else : ?>
            <input type="<?php echo esc_attr($type); ?>"
                name="<?php echo esc_attr($field_name); ?>"
                value="<?php echo esc_attr($value); ?>"
                class="<?php echo esc_attr($css_class); ?>"
                <?php if (!empty($placeholder)) : ?>placeholder="<?php echo esc_attr($placeholder); ?>"<?php endif; ?>>
        <?php endif; ?>

        <?php if (!empty($description) && $type !== 'toggle') : ?>
            <p class="description"><?php echo esc_html($description); ?></p>
        <?php endif; ?>
    </td>
</tr>
