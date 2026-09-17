.PHONY: bridge-install bridge-test adapter-build adapter-test adapter-deploy \
        contracts-install contracts-validate tshock-setup tshock-run

## Bridge (Laravel / PHP)
bridge-install:
	cd bridge && composer install
	cd bridge && [ -f .env ] || cp .env.example .env
	cd bridge && php artisan key:generate

bridge-test:
	cd bridge && composer test

## Adapter (C# / TShock Plugin)
adapter-build:
	dotnet build adapter/TerrariaBacklog.Adapter.csproj

adapter-test:
	dotnet test adapter/Tests/TerrariaBacklog.Adapter.Tests.csproj

adapter-deploy: adapter-build
	./scripts/deploy-adapter.sh

## contracts (JSON Schema)
contracts-install:
	cd contracts && npm install

contracts-validate:
	cd contracts && npm run validate

## Local TShock Dedicated Server
tshock-setup:
	./scripts/setup-tshock.sh

# TShock 4.3.13 は .NET Framework 4.5 向けのため、起動には Mono が必要 (docs/design.md §2.3)。
tshock-run:
	@command -v mono >/dev/null 2>&1 || { echo "mono not found. See README.md (macOS: brew install mono / WSL Ubuntu: sudo apt install mono-complete)."; exit 1; }
	cd .tshock-server && mono TerrariaServer.exe -world worlds/dev.wld -autocreate 2
