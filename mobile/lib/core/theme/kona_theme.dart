import 'package:flutter/material.dart';

class KonaColors {
  const KonaColors._();

  static const gold = Color(0xFFFFC400);
  static const goldDark = Color(0xFF9A6A00);
  static const ink = Color(0xFF11110F);
  static const canvas = Color(0xFFF7F4ED);
  static const surface = Color(0xFFFFFFFF);
  static const soft = Color(0xFFF1EBDD);
  static const line = Color(0xFFE3DCCB);
  static const muted = Color(0xFF6F706F);
  static const success = Color(0xFF18794E);
  static const danger = Color(0xFFC63D2F);
  static const info = Color(0xFF245E8A);
}

class KonaTheme {
  const KonaTheme._();

  static ThemeData get light {
    final scheme =
        ColorScheme.fromSeed(
          seedColor: KonaColors.gold,
          brightness: Brightness.light,
          surface: KonaColors.surface,
        ).copyWith(
          primary: KonaColors.ink,
          onPrimary: Colors.white,
          secondary: KonaColors.gold,
          onSecondary: KonaColors.ink,
          error: KonaColors.danger,
          outline: KonaColors.line,
        );

    final base = ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
      scaffoldBackgroundColor: KonaColors.canvas,
      fontFamily: 'KonaDIN',
      splashFactory: InkSparkle.splashFactory,
    );

    return base.copyWith(
      textTheme: base.textTheme.copyWith(
        displaySmall: const TextStyle(
          fontWeight: FontWeight.w700,
          color: KonaColors.ink,
          letterSpacing: -1.1,
        ),
        headlineMedium: const TextStyle(
          fontWeight: FontWeight.w700,
          color: KonaColors.ink,
          letterSpacing: -.7,
        ),
        titleLarge: const TextStyle(
          fontWeight: FontWeight.w700,
          color: KonaColors.ink,
        ),
        titleMedium: const TextStyle(
          fontWeight: FontWeight.w700,
          color: KonaColors.ink,
        ),
        bodyLarge: const TextStyle(height: 1.35, color: KonaColors.ink),
        bodyMedium: const TextStyle(height: 1.35, color: KonaColors.ink),
        labelLarge: const TextStyle(fontWeight: FontWeight.w700),
      ),
      appBarTheme: const AppBarTheme(
        backgroundColor: KonaColors.canvas,
        foregroundColor: KonaColors.ink,
        elevation: 0,
        scrolledUnderElevation: 0,
        centerTitle: false,
      ),
      cardTheme: CardThemeData(
        elevation: 0,
        color: KonaColors.surface,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(22),
          side: const BorderSide(color: KonaColors.line),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: KonaColors.surface,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 18,
          vertical: 16,
        ),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(16),
          borderSide: const BorderSide(color: KonaColors.line),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(16),
          borderSide: const BorderSide(color: KonaColors.line),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(16),
          borderSide: const BorderSide(color: KonaColors.gold, width: 2),
        ),
        hintStyle: const TextStyle(color: KonaColors.muted),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          minimumSize: const Size(48, 54),
          backgroundColor: KonaColors.gold,
          foregroundColor: KonaColors.ink,
          elevation: 0,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(16),
          ),
          textStyle: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: const Size(48, 52),
          foregroundColor: KonaColors.ink,
          side: const BorderSide(color: KonaColors.line),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(16),
          ),
          textStyle: const TextStyle(fontWeight: FontWeight.w700),
        ),
      ),
      chipTheme: base.chipTheme.copyWith(
        backgroundColor: KonaColors.soft,
        selectedColor: const Color(0xFFFFE7A3),
        side: BorderSide.none,
        labelStyle: const TextStyle(
          color: KonaColors.ink,
          fontWeight: FontWeight.w700,
        ),
        secondaryLabelStyle: const TextStyle(
          color: KonaColors.ink,
          fontWeight: FontWeight.w700,
        ),
        checkmarkColor: KonaColors.ink,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(999)),
      ),
      dividerTheme: const DividerThemeData(
        color: KonaColors.line,
        thickness: 1,
      ),
      navigationBarTheme: NavigationBarThemeData(
        height: 70,
        backgroundColor: KonaColors.surface,
        indicatorColor: KonaColors.gold.withValues(alpha: .2),
        elevation: 0,
        labelTextStyle: WidgetStateProperty.resolveWith(
          (states) => TextStyle(
            fontSize: 11,
            fontWeight: states.contains(WidgetState.selected)
                ? FontWeight.w700
                : FontWeight.w500,
            color: KonaColors.ink,
          ),
        ),
      ),
    );
  }
}
