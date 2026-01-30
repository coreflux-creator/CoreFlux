<?php
function get_custom_fields($pdo, $module, $tenant_id) {
    $stmt = $pdo->prepare("SELECT * FROM custom_fields WHERE module = ? AND tenant_id = ?");
    $stmt->execute([$module, $tenant_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function render_custom_fields($pdo, $module, $record_id, $tenant_id) {
    $fields = get_custom_fields($pdo, $module, $tenant_id);
    foreach ($fields as $field) {
        $value = get_custom_field_value($pdo, $field['id'], $record_id, $tenant_id);
        echo "<label>{$field['field_label']}</label><br>";
        switch ($field['field_type']) {
            case 'text':
                echo "<input type='text' name='custom[{$field['id']}]' value='" . htmlspecialchars($value) . "'><br>";
                break;
            case 'number':
                echo "<input type='number' name='custom[{$field['id']}]' value='" . htmlspecialchars($value) . "'><br>";
                break;
            case 'date':
                echo "<input type='date' name='custom[{$field['id']}]' value='" . htmlspecialchars($value) . "'><br>";
                break;
            case 'boolean':
                $checked = ($value == '1') ? 'checked' : '';
                echo "<input type='checkbox' name='custom[{$field['id']}]' value='1' $checked><br>";
                break;
            case 'dropdown':
                $options = explode(',', $field['options']);
                echo "<select name='custom[{$field['id']}]'>";
                foreach ($options as $opt) {
                    $selected = ($value == trim($opt)) ? 'selected' : '';
                    echo "<option value='" . htmlspecialchars(trim($opt)) . "' $selected>" . htmlspecialchars(trim($opt)) . "</option>";
                }
                echo "</select><br>";
                break;
        }
    }
}

function get_custom_field_value($pdo, $field_id, $record_id, $tenant_id) {
    $stmt = $pdo->prepare("SELECT value FROM custom_values WHERE field_id = ? AND record_id = ? AND tenant_id = ?");
    $stmt->execute([$field_id, $record_id, $tenant_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row['value'] ?? '';
}

function save_custom_values($pdo, $module, $record_id, $post_data, $tenant_id) {
    if (!isset($post_data['custom'])) return;

    foreach ($post_data['custom'] as $field_id => $value) {
        $stmt = $pdo->prepare("SELECT id FROM custom_values WHERE field_id = ? AND record_id = ? AND tenant_id = ?");
        $stmt->execute([$field_id, $record_id, $tenant_id]);
        if ($stmt->fetch()) {
            $update = $pdo->prepare("UPDATE custom_values SET value = ? WHERE field_id = ? AND record_id = ? AND tenant_id = ?");
            $update->execute([$value, $field_id, $record_id, $tenant_id]);
        } else {
            $insert = $pdo->prepare("INSERT INTO custom_values (field_id, record_id, value, tenant_id) VALUES (?, ?, ?, ?)");
            $insert->execute([$field_id, $record_id, $value, $tenant_id]);
        }
    }
} ?>