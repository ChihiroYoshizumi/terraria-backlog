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

tshock-run:
	cd .tshock-server && ./TShock.Server -world worlds/dev.wld -autocreate 2
