<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedMissingDataCommand extends Command
{
    protected $signature = "db:seed-missing {--dry-run : Show what would be inserted without committing}";
    protected $description = "Seed expense_categories, fee_types, employees missing after SQLite->MySQL migration";

    public function handle(): int
    {
        $isDry = $this->option("dry-run");
        if ($isDry) $this->warn("DRY-RUN mode.");
        DB::beginTransaction();
        try {
            $this->info("1/3 expense_categories...");
            $cats = [
                [1,"\u0645\u0648\u0627\u062f \u062a\u0646\u0638\u064a\u0641"],[2,"\u0623\u062f\u0648\u0627\u062a \u0645\u0643\u062a\u0628\u064a\u0629"],[3,"\u0635\u064a\u0627\u0646\u0629 \u0648\u0625\u0635\u0644\u0627\u062d"],
                [4,"\u0643\u0647\u0631\u0628\u0627\u0621 \u0648\u0645\u0627\u0621"],[5,"\u0627\u062a\u0635\u0627\u0644\u0627\u062a \u0648\u0625\u0646\u062a\u0631\u0646\u062a"],[6,"\u0646\u0642\u0644 \u0648\u062a\u0646\u0642\u0651\u0644"],
                [7,"\u062a\u063a\u0630\u064a\u0629"],[8,"\u0645\u0639\u062f\u0627\u062a \u0648\u062a\u062c\u0647\u064a\u0632\u0627\u062a"],[9,"\u062e\u062f\u0645\u0627\u062a \u062e\u0627\u0631\u062c\u064a\u0629"],[10,"\u0645\u062a\u0641\u0631\u0642\u0627\u062a"],
            ];
            $ci=0; $cs=0;
            foreach ($cats as [$id,$name]) {
                if (DB::table("expense_categories")->where("id",$id)->exists() || DB::table("expense_categories")->where("name",$name)->exists()) {
                    $cs++;
                } else {
                    if (!$isDry) DB::table("expense_categories")->insert(["id"=>$id,"name"=>$name,"is_active"=>1,"notes"=>null,"created_at"=>now(),"updated_at"=>now()]);
                    $ci++; $this->line("  + [$id] $name");
                }
            }
            $this->info("   expense_categories: inserted=$ci skipped=$cs");

            $this->info("2/3 fee_types...");
            $fts = [
                [2,"\u0645\u064a\u062f\u0639\u0629","Inscription",30,"product_sale"],
                [3,"\u0627\u0644\u062a\u062c\u0647\u064a\u0632\u0627\u062a","\u00c9quipements",40,"product_sale"],
                [4,"ERP vie scolaire","ERP vie scolaire",20,"other_income"],
                [5,"\u062d\u0636\u0627\u0646\u0629","Garderie",30,"other_income"],
                [6,"\u0646\u0627\u062f\u064a \u0627\u0644\u0631\u0648\u0628\u0648\u062a\u0643","Club Robotique",20,"club_fee"],
                [7,"\u062d\u0633\u0627\u0628 \u0630\u0647\u0646\u064a","Calcul Mental",20,"club_fee"],
                [8,"\u0645\u0639\u0644\u0648\u0645 \u0627\u0644\u062a\u0631\u0633\u064a\u0645",null,70,"registration_fee"],
                [9,"\u0627\u0644\u0645\u0639\u0644\u0648\u0645 \u0627\u0644\u0634\u0647\u0631\u064a",null,0,"monthly_fee"],
            ];
            $fi=0; $fs=0;
            foreach ($fts as [$id,$ar,$fr,$price,$lc]) {
                if (DB::table("fee_types")->where("id",$id)->exists() || DB::table("fee_types")->where("name_ar",$ar)->exists()) {
                    if (!$isDry) DB::table("fee_types")->where("id",$id)->whereNull("ledger_category")->update(["ledger_category"=>$lc,"updated_at"=>now()]);
                    $fs++;
                } else {
                    if (!$isDry) DB::table("fee_types")->insert(["id"=>$id,"name_ar"=>$ar,"name_fr"=>$fr,"price"=>$price,"is_active"=>1,"ledger_category"=>$lc,"created_at"=>now(),"updated_at"=>now()]);
                    $fi++; $this->line("  + [$id] $ar");
                }
            }
            $this->info("   fee_types: inserted=$fi skipped=$fs");

            $this->info("3/3 employees from SQLite...");
            $sqlitePath = database_path("database.sqlite");
            if (!file_exists($sqlitePath)) {
                $this->warn("   SQLite not found at $sqlitePath");
            } else {
                config(["database.connections.sqlite_legacy"=>["driver"=>"sqlite","database"=>$sqlitePath,"prefix"=>"","foreign_key_constraints"=>false]]);
                $emps = DB::connection("sqlite_legacy")->table("employees")->get();
                $ei=0; $es=0;
                foreach ($emps as $e) {
                    if (DB::table("employees")->where("id",$e->id)->exists()) { $es++; }
                    else {
                        if (!$isDry) DB::table("employees")->insert([
                            "id"=>$e->id,"first_name"=>$e->first_name,"last_name"=>$e->last_name,
                            "phone"=>$e->phone??null,"email"=>$e->email??null,"job_title"=>$e->job_title??null,
                            "default_salary"=>$e->default_salary??null,"is_active"=>$e->is_active??1,"notes"=>$e->notes??null,
                            "staff_type"=>$e->staff_type??null,"salary_type"=>$e->salary_type??null,
                            "hourly_rate"=>$e->hourly_rate??null,"monthly_salary"=>$e->monthly_salary??null,
                            "hire_date"=>$e->hire_date??null,"created_at"=>$e->created_at??now(),"updated_at"=>$e->updated_at??now(),
                        ]);
                        $ei++; $this->line("  + [{$e->id}] {$e->first_name} {$e->last_name}");
                    }
                }
                $this->info("   employees: inserted=$ei skipped=$es");
            }

            if ($isDry) { DB::rollBack(); $this->warn("DRY-RUN done."); }
            else {
                DB::commit();
                $this->info("");
                $this->info("Done!");
                $this->table(["Table","Count"],[
                    ["expense_categories", DB::table("expense_categories")->count()],
                    ["fee_types",          DB::table("fee_types")->count()],
                    ["employees",          DB::table("employees")->count()],
                ]);
            }
            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error("FAILED: ".$e->getMessage()." @ ".$e->getFile().":".$e->getLine());
            return self::FAILURE;
        }
    }
}