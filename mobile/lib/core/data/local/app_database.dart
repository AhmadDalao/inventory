import 'package:drift/drift.dart';
import 'package:drift_flutter/drift_flutter.dart';

part 'app_database.g.dart';

class PendingOperations extends Table {
  TextColumn get operationId => text()();
  TextColumn get type => text()();
  TextColumn get title => text()();
  TextColumn get payloadJson => text()();
  TextColumn get state => text().withDefault(const Constant('draft'))();
  TextColumn get message => text().nullable()();
  DateTimeColumn get createdAt => dateTime()();
  DateTimeColumn get updatedAt => dateTime()();

  @override
  Set<Column<Object>> get primaryKey => {operationId};
}

@DriftDatabase(tables: [PendingOperations])
class AppDatabase extends _$AppDatabase {
  AppDatabase()
    : super(
        driftDatabase(
          name: 'inventory_kona',
          web: DriftWebOptions(
            sqlite3Wasm: Uri.parse('sqlite3.wasm'),
            driftWorker: Uri.parse('drift_worker.js'),
          ),
        ),
      );

  @override
  int get schemaVersion => 1;
}
