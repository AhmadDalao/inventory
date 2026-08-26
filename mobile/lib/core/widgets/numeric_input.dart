import 'package:flutter/material.dart';

/// Selects the complete value after Flutter finishes positioning the caret.
///
/// Operational quantity fields are prefilled from server data. Replacing the
/// value is safer than appending digits to it, especially on Android keyboards.
void selectAllNumericTextOnTap() {
  WidgetsBinding.instance.addPostFrameCallback((_) {
    final editable = FocusManager.instance.primaryFocus?.context
        ?.findAncestorStateOfType<EditableTextState>();
    editable?.selectAll(SelectionChangedCause.tap);
  });
}
