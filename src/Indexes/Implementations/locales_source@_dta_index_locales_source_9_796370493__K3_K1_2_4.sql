CREATE NONCLUSTERED INDEX [_dta_index_locales_source_9_796370493__K3_K1_2_4] ON [dbo].[locales_source]
(
	[context] ASC,
	[lid] ASC
)
INCLUDE ([source],[version]) WITH (SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF) ON [PRIMARY]